<?php

declare(strict_types=1);

namespace App\Repository;

use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonPayableException;
use App\Exception\PaiementEnCoursException;
use App\Service\Paiement\ContextePaiement;
use App\Service\Paiement\PaiementExistant;
use App\Service\Paiement\PaiementOuvert;
use App\Service\Paiement\Paiements;
use App\Service\Panier\PanierId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Paiements (DBAL, SQL paramétré, compte sk_app).
 *
 * Deux transactions COURTES encadrent l'appel au prestataire, qui se fait entre elles, sans aucun verrou tenu (CDC §10.3) :
 *   ouvrir()   : verrou de la commande, contrôle de l'état, insertion de la tentative EN_ATTENTE ;
 *   accepter() : résultat du paiement ET avancée de la commande, ensemble ou pas du tout.
 * Entre les deux, ce qui empêche un second débit n'est pas un contrôle applicatif mais l'index unique partiel
 * uq_paiement_commande_actif : deux ouvertures simultanées, le second INSERT échoue. READ COMMITTED suffit : chaque décision
 * repose sur une ligne verrouillée (la commande), relue à jour après l'attente.
 */
final class PaiementRepository implements Paiements
{
    /** Une tentative EN_ATTENTE plus ancienne que cela est une tentative abandonnée (processus mort avant la réponse). */
    private const ABANDON_SECONDES = 60;

    public function __construct(private readonly Connection $connexion)
    {
    }

    public function parCle(string $empreinte, string $utilisateurId): ?PaiementExistant
    {
        $ligne = $this->connexion->fetchAssociative(
            <<<'SQL'
                SELECT p.id::text AS id, p.statut, p.code_retour, p.derniers_chiffres, c.reference::text AS reference
                  FROM paiement p JOIN commande c ON c.id = p.commande_id
                 WHERE p.cle_idempotence = :cle AND c.utilisateur_id = CAST(:u AS uuid)
                SQL,
            ['cle' => $empreinte, 'u' => $utilisateurId],
        );

        return false === $ligne ? null : new PaiementExistant(
            (string) $ligne['id'], (string) $ligne['statut'],
            null === $ligne['code_retour'] ? null : (string) $ligne['code_retour'],
            null === $ligne['derniers_chiffres'] ? null : (string) $ligne['derniers_chiffres'],
            (string) $ligne['reference'],
        );
    }

    public function contexte(string $reference, string $utilisateurId): ?ContextePaiement
    {
        if (!PanierId::estUuid($reference)) {
            return null;   // jamais d'erreur de conversion uuid côté base pour une valeur forgée
        }
        $ligne = $this->connexion->fetchAssociative(
            <<<'SQL'
                SELECT c.id::text AS id, c.reference::text AS reference, c.statut, (c.montant_total * 100)::integer AS montant,
                       c.expire_le, u.cree_le AS compte_cree_le,
                       coalesce((SELECT sum(l.quantite) FROM ligne_commande l WHERE l.commande_id = c.id), 0)::integer AS cles,
                       NOT EXISTS (
                           SELECT 1 FROM commande autre
                            WHERE autre.utilisateur_id = c.utilisateur_id AND autre.id <> c.id
                              AND autre.statut IN ('PAYEE', 'A_VERIFIER', 'REMBOURSEE')
                       ) AS premiere
                  FROM commande c JOIN utilisateur u ON u.id = c.utilisateur_id
                 WHERE c.reference = CAST(:r AS uuid) AND c.utilisateur_id = CAST(:u AS uuid)
                SQL,
            ['r' => strtolower($reference), 'u' => $utilisateurId],
        );
        if (false === $ligne) {
            return null;
        }

        return new ContextePaiement(
            (string) $ligne['id'], (string) $ligne['reference'], (string) $ligne['statut'], (int) $ligne['montant'], (int) $ligne['cles'],
            new \DateTimeImmutable((string) $ligne['expire_le']), new \DateTimeImmutable((string) $ligne['compte_cree_le']),
            (bool) $ligne['premiere'],
        );
    }

    public function ouvrir(string $reference, string $utilisateurId, string $empreinte, int $margeSecondes): PaiementOuvert
    {
        if (!PanierId::estUuid($reference)) {
            throw new CommandeIntrouvableException();
        }

        return $this->connexion->transactional(function (Connection $c) use ($reference, $utilisateurId, $empreinte, $margeSecondes): PaiementOuvert {
            $c->executeStatement("SELECT set_config('sidekey.auteur_id', :u, true)", ['u' => $utilisateurId]);

            $commande = $c->fetchAssociative(
                <<<'SQL'
                    SELECT id::text AS id, statut, (expire_le > now() + make_interval(secs => :marge)) AS valide
                      FROM commande
                     WHERE reference = CAST(:r AS uuid) AND utilisateur_id = CAST(:u AS uuid)
                       FOR UPDATE
                    SQL,
                ['r' => strtolower($reference), 'u' => $utilisateurId, 'marge' => $margeSecondes],
            );
            if (false === $commande) {
                throw new CommandeIntrouvableException();
            }
            if ('EN_ATTENTE_PAIEMENT' !== $commande['statut'] || !$commande['valide']) {
                throw new CommandeNonPayableException();
            }

            // Une tentative restée EN_ATTENTE bien au-delà d'un appel normal n'aboutira pas : elle ne doit pas bloquer la commande
            $c->executeStatement(
                "UPDATE paiement SET statut = 'ERREUR', code_retour = 'abandonne', modifie_le = now()
                  WHERE commande_id = CAST(:c AS uuid) AND statut = 'EN_ATTENTE' AND cree_le < now() - make_interval(secs => :abandon)",
                ['c' => $commande['id'], 'abandon' => self::ABANDON_SECONDES],
            );

            try {
                $id = (string) $c->fetchOne(
                    "INSERT INTO paiement (montant, statut, cle_idempotence, commande_id)
                     SELECT montant_total, 'EN_ATTENTE', :cle, id FROM commande WHERE id = CAST(:c AS uuid)
                     RETURNING id::text",
                    ['cle' => $empreinte, 'c' => $commande['id']],
                );
            } catch (UniqueConstraintViolationException) {
                // uq_paiement_commande_actif (un autre paiement en cours) ou uq_paiement_idempotence (même clé en parallèle)
                throw new PaiementEnCoursException();
            }

            return new PaiementOuvert($id, (string) $commande['id']);
        });
    }

    public function accepter(string $paiementId, string $codeRetour, string $derniersChiffres, bool $suspecte, string $utilisateurId): string
    {
        return $this->connexion->transactional(function (Connection $c) use ($paiementId, $codeRetour, $derniersChiffres, $suspecte, $utilisateurId): string {
            $c->executeStatement("SELECT set_config('sidekey.auteur_id', :u, true)", ['u' => $utilisateurId]);

            $commandeId = $c->fetchOne(
                "UPDATE paiement SET statut = 'ACCEPTE', code_retour = :code, derniers_chiffres = :d, modifie_le = now()
                  WHERE id = CAST(:p AS uuid) AND statut = 'EN_ATTENTE'
                  RETURNING commande_id::text",
                ['code' => $codeRetour, 'd' => $derniersChiffres, 'p' => $paiementId],
            );
            if (false === $commandeId) {
                throw new CommandeNonPayableException();   // tentative abandonnée entre-temps : rien n'est accepté
            }

            if ($suspecte) {
                $commande = $c->fetchAssociative(
                    'SELECT statut, (expire_le > now()) AS valide FROM commande WHERE id = CAST(:c AS uuid) FOR UPDATE',
                    ['c' => $commandeId],
                );
                if (false === $commande || 'EN_ATTENTE_PAIEMENT' !== $commande['statut'] || !$commande['valide']) {
                    throw new CommandeNonPayableException();
                }
                // Les clés restent RESERVEE : non vendues, non révélables, jusqu'à la décision du back-office (F-10)
                $c->executeStatement("UPDATE commande SET statut = 'A_VERIFIER', modifie_le = now() WHERE id = CAST(:c AS uuid)", ['c' => $commandeId]);

                return 'A_VERIFIER';
            }

            try {
                return (string) $c->fetchOne('SELECT fn_valider_commande(CAST(:c AS uuid))', ['c' => $commandeId]);
            } catch (DriverException $e) {
                throw 'SK005' === $e->getSQLState() ? new CommandeNonPayableException() : $e;
            }
        });
    }

    public function terminer(string $paiementId, string $statut, string $codeRetour, ?string $derniersChiffres): void
    {
        $this->connexion->executeStatement(
            "UPDATE paiement SET statut = :s, code_retour = :code, derniers_chiffres = :d, modifie_le = now()
              WHERE id = CAST(:p AS uuid) AND statut = 'EN_ATTENTE'",
            ['s' => $statut, 'code' => $codeRetour, 'd' => $derniersChiffres, 'p' => $paiementId],
        );
    }
}
