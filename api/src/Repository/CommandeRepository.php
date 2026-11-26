<?php

declare(strict_types=1);

namespace App\Repository;

use App\Exception\CommandeEnCoursException;
use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonAnnulableException;
use App\Exception\OffreIndisponibleException;
use App\Service\Catalogue\Langue;
use App\Service\Commande\CleCommande;
use App\Service\Commande\CommandeAnnulee;
use App\Service\Commande\CommandeCreee;
use App\Service\Commande\CommandeDetail;
use App\Service\Commande\CommandeResume;
use App\Service\Commande\Commandes;
use App\Service\Commande\LigneACommander;
use App\Service\Commande\LigneCommandeDetail;
use App\Service\Commande\OffreCommandable;
use App\Service\Panier\PanierId;
use App\Service\Reservation\ReservationCles;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;

/**
 * Commandes (DBAL, SQL paramétré, compte sk_app).
 *
 * Transaction de création (CDC §10.3) — READ COMMITTED, fixé explicitement, et verrous de ligne :
 *   1. verrou du compte client (FOR UPDATE) : deux requêtes du même client passent l'une après l'autre,
 *      la seconde voit la commande de la première et s'arrête (pas de double réservation) ;
 *   2. verrou partagé des offres (FOR SHARE) : le prix lu est celui qui est enregistré (RG-05) ;
 *   3. commande, lignes au prix du jour, puis fn_reserver_cles par ligne (FOR UPDATE SKIP LOCKED) :
 *      deux clients sur la dernière clé, l'un l'obtient, l'autre reçoit SK001 et sa transaction est annulée.
 * READ COMMITTED suffit : chaque décision repose sur une ligne verrouillée, relue à jour après l'attente.
 * SERIALIZABLE ferait échouer des transactions qui ne se gênent pas, à rejouer côté application.
 */
final class CommandeRepository implements Commandes
{
    public function __construct(
        private readonly Connection $connexion,
        private readonly ReservationCles $reservations,
    ) {
    }

    public function offres(array $offreIds): array
    {
        $ids = array_values(array_filter($offreIds, PanierId::estUuid(...)));
        if ([] === $ids) {
            return [];
        }
        $offres = [];
        foreach ($this->connexion->fetchAllAssociative(
            'SELECT o.id::text AS id, j.slug, j.pegi, o.actif FROM offre o JOIN jeu j ON j.id = o.jeu_id WHERE o.id::text IN (:ids)',
            ['ids' => array_map(strtolower(...), $ids)],
            ['ids' => ArrayParameterType::STRING],
        ) as $ligne) {
            $offres[(string) $ligne['id']] = new OffreCommandable((string) $ligne['id'], (string) $ligne['slug'], (int) $ligne['pegi'], (bool) $ligne['actif']);
        }

        return $offres;
    }

    public function creer(string $utilisateurId, Langue $langue, bool $confirmeMajeur, array $lignes, int $dureeReservationSecondes): CommandeCreee
    {
        return $this->connexion->transactional(function (Connection $c) use ($utilisateurId, $langue, $confirmeMajeur, $lignes, $dureeReservationSecondes): CommandeCreee {
            $c->executeStatement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            // Auteur des lignes d'historique écrites par trg_commande_historique (RG-21), pour cette transaction
            $c->executeStatement("SELECT set_config('sidekey.auteur_id', :u, true)", ['u' => $utilisateurId]);

            $c->executeStatement('SELECT 1 FROM utilisateur WHERE id = CAST(:u AS uuid) FOR UPDATE', ['u' => $utilisateurId]);
            $enCours = $c->fetchOne(
                "SELECT reference::text FROM commande WHERE utilisateur_id = CAST(:u AS uuid) AND statut = 'EN_ATTENTE_PAIEMENT' AND expire_le > now() LIMIT 1",
                ['u' => $utilisateurId],
            );
            if (false !== $enCours) {
                throw new CommandeEnCoursException((string) $enCours);
            }

            $ids = array_map(static fn (LigneACommander $l): string => strtolower($l->offreId), $lignes);
            $offres = [];
            foreach ($c->fetchAllAssociative(
                'SELECT o.id::text AS id, o.prix_ttc, (o.prix_ttc * 100)::integer AS centimes, j.slug FROM offre o JOIN jeu j ON j.id = o.jeu_id WHERE o.id::text IN (:ids) AND o.actif ORDER BY o.id FOR SHARE OF o',
                ['ids' => $ids],
                ['ids' => ArrayParameterType::STRING],
            ) as $offre) {
                $offres[(string) $offre['id']] = $offre;
            }
            $totalCentimes = 0;   // RG-11 : calculé ici, en centimes entiers, à partir des prix en base
            foreach ($lignes as $ligne) {
                $offre = $offres[strtolower($ligne->offreId)] ?? throw new OffreIndisponibleException();
                $totalCentimes += (int) $offre['centimes'] * $ligne->quantite;
            }

            $commande = $c->fetchAssociative(
                <<<'SQL'
                    INSERT INTO commande (statut, montant_total, confirme_majeur, expire_le, utilisateur_id, langue_code)
                    VALUES ('EN_ATTENTE_PAIEMENT', CAST(:total AS integer) / 100.0, :majeur, now() + make_interval(secs => :duree),
                            CAST(:u AS uuid), :langue)
                    RETURNING id::text AS id, reference::text AS reference, expire_le
                    SQL,
                ['total' => $totalCentimes, 'majeur' => $confirmeMajeur ? 'true' : 'false', 'duree' => $dureeReservationSecondes, 'u' => $utilisateurId, 'langue' => $langue->value],
            );
            \assert(\is_array($commande));

            // Ordre stable des offres : deux commandes qui se disputent les mêmes offres les verrouillent
            // dans le même ordre, sans interblocage
            $parOffre = $lignes;
            usort($parOffre, static fn (LigneACommander $a, LigneACommander $b): int => strtolower($a->offreId) <=> strtolower($b->offreId));
            foreach ($parOffre as $ligne) {
                $offreId = strtolower($ligne->offreId);
                $ligneId = (string) $c->fetchOne(
                    'INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (:q, CAST(:prix AS numeric), CAST(:c AS uuid), CAST(:o AS uuid)) RETURNING id::text',
                    ['q' => $ligne->quantite, 'prix' => (string) $offres[$offreId]['prix_ttc'], 'c' => $commande['id'], 'o' => $offreId],
                );
                $this->reservations->reserverCles($offreId, $ligne->quantite, $ligneId);
            }

            return new CommandeCreee(
                (string) $commande['id'],
                (string) $commande['reference'],
                new \DateTimeImmutable((string) $commande['expire_le']),
                array_values(array_unique(array_map(static fn (array $o): string => (string) $o['slug'], $offres))),
            );
        });
    }

    public function detail(string $reference, string $utilisateurId): ?CommandeDetail
    {
        if (!PanierId::estUuid($reference)) {
            return null;   // jamais d'erreur de conversion uuid côté base pour une valeur forgée
        }
        $commande = $this->connexion->fetchAssociative(
            <<<'SQL'
                SELECT id::text AS id, reference::text AS reference, statut, (montant_total * 100)::integer AS montant,
                       cree_le, expire_le, payee_le
                  FROM commande
                 WHERE reference = CAST(:r AS uuid) AND utilisateur_id = CAST(:u AS uuid)
                SQL,
            ['r' => strtolower($reference), 'u' => $utilisateurId],
        );
        if (false === $commande) {
            return null;
        }

        $cles = [];
        if ('PAYEE' === $commande['statut']) {
            foreach ($this->connexion->fetchAllAssociative(
                "SELECT k.id::text AS id, k.ligne_commande_id::text AS ligne, k.revelee_le FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id WHERE l.commande_id = CAST(:c AS uuid) AND k.statut = 'VENDUE' ORDER BY k.id",
                ['c' => $commande['id']],
            ) as $cle) {
                $cles[(string) $cle['ligne']][] = new CleCommande((string) $cle['id'], self::date($cle['revelee_le']));
            }
        }

        $lignes = array_map(
            static fn (array $l): LigneCommandeDetail => new LigneCommandeDetail(
                (string) $l['offre_id'], (string) $l['titre'], (string) $l['plateforme'], (int) $l['quantite'], (int) $l['prix'], $cles[(string) $l['id']] ?? [],
            ),
            $this->connexion->fetchAllAssociative(
                <<<'SQL'
                    SELECT l.id::text AS id, l.offre_id::text AS offre_id, j.titre, p.libelle AS plateforme, l.quantite,
                           (l.prix_unitaire * 100)::integer AS prix
                      FROM ligne_commande l
                      JOIN offre o      ON o.id = l.offre_id
                      JOIN jeu j        ON j.id = o.jeu_id
                      JOIN plateforme p ON p.id = o.plateforme_id
                     WHERE l.commande_id = CAST(:c AS uuid)
                     ORDER BY j.titre, p.libelle
                    SQL,
                ['c' => $commande['id']],
            ),
        );

        return new CommandeDetail(
            (string) $commande['id'], (string) $commande['reference'], (string) $commande['statut'], (int) $commande['montant'],
            new \DateTimeImmutable((string) $commande['cree_le']), new \DateTimeImmutable((string) $commande['expire_le']),
            self::date($commande['payee_le']), $lignes,
        );
    }

    public function lister(string $utilisateurId, int $limite): array
    {
        return array_map(
            static fn (array $c): CommandeResume => new CommandeResume(
                (string) $c['reference'], (string) $c['statut'], (int) $c['montant'], (int) $c['articles'], new \DateTimeImmutable((string) $c['cree_le']),
            ),
            $this->connexion->fetchAllAssociative(
                <<<'SQL'
                    SELECT c.reference::text AS reference, c.statut, (c.montant_total * 100)::integer AS montant, c.cree_le,
                           coalesce((SELECT sum(l.quantite) FROM ligne_commande l WHERE l.commande_id = c.id), 0) AS articles
                      FROM commande c
                     WHERE c.utilisateur_id = CAST(:u AS uuid)
                     ORDER BY c.cree_le DESC, c.id
                     LIMIT :limite
                    SQL,
                ['u' => $utilisateurId, 'limite' => $limite],
                ['limite' => \Doctrine\DBAL\ParameterType::INTEGER],
            ),
        );
    }

    public function annuler(string $reference, string $utilisateurId): CommandeAnnulee
    {
        if (!PanierId::estUuid($reference)) {
            throw new CommandeIntrouvableException();
        }

        return $this->connexion->transactional(function (Connection $c) use ($reference, $utilisateurId): CommandeAnnulee {
            $c->executeStatement("SELECT set_config('sidekey.auteur_id', :u, true)", ['u' => $utilisateurId]);
            $id = $c->fetchOne(
                'SELECT id::text FROM commande WHERE reference = CAST(:r AS uuid) AND utilisateur_id = CAST(:u AS uuid)',
                ['r' => strtolower($reference), 'u' => $utilisateurId],
            );
            if (false === $id) {
                throw new CommandeIntrouvableException();
            }
            $slugs = $c->fetchFirstColumn(
                'SELECT DISTINCT j.slug FROM ligne_commande l JOIN offre o ON o.id = l.offre_id JOIN jeu j ON j.id = o.jeu_id WHERE l.commande_id = CAST(:c AS uuid)',
                ['c' => $id],
            );
            try {
                $liberees = (int) $c->fetchOne('SELECT fn_annuler_commande(CAST(:c AS uuid))', ['c' => $id]);
            } catch (DriverException $e) {
                throw 'SK005' === $e->getSQLState() ? new CommandeNonAnnulableException() : $e;
            }

            return new CommandeAnnulee((string) $id, $liberees, array_map(strval(...), $slugs));
        });
    }

    private static function date(mixed $valeur): ?\DateTimeImmutable
    {
        return null === $valeur ? null : new \DateTimeImmutable((string) $valeur);
    }
}
