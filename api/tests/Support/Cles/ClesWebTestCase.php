<?php

declare(strict_types=1);

namespace App\Tests\Support\Cles;

use App\Repository\UtilisateurRepository;
use App\Service\Cles\ChiffrementCles;
use App\Service\Cles\CleNormalisee;
use App\Tests\Support\Auth\AuthWebTestCase;
use App\Tests\Support\Auth\FabriqueCompte;

/**
 * Base des tests HTTP de F-08 : API réelle, PostgreSQL et Redis réels, secrets de .env.test.
 *
 * Chaque test crée sa propre offre (sur une plateforme sans offre active pour le jeu) : les clés importées
 * ne touchent pas au stock du jeu d'essai, et tout est retiré à la fin (commandes en attente annulées ;
 * une offre gardée par une ligne de commande est seulement retirée de la vente). Les commandes et les clés VENDUES
 * des tests de révélation restent en base (une clé vendue ne se supprime pas, trigger RG-04), sur des
 * comptes « cles-test-* » dédiés : elles ne gênent aucun autre test.
 */
abstract class ClesWebTestCase extends AuthWebTestCase
{
    public const GESTIONNAIRE = 'gestionnaire@sidekey.test';

    /** @var list<string> */
    private array $offresCreees = [];

    protected function tearDown(): void
    {
        foreach ($this->offresCreees as $offre) {
            // Commandes encore en attente sur l'offre (tests F-06) : annulées, leurs clés reviennent en stock
            $this->connexion->executeStatement(
                "SELECT fn_annuler_commande(c.id) FROM commande c WHERE c.statut = 'EN_ATTENTE_PAIEMENT' AND c.expire_le > now() AND EXISTS (SELECT 1 FROM ligne_commande l WHERE l.commande_id = c.id AND l.offre_id = CAST(:o AS uuid))",
                ['o' => $offre],
            );
            $this->connexion->executeStatement("SELECT fn_liberer_reservations_expirees()");
            $this->connexion->executeStatement("DELETE FROM cle_licence WHERE offre_id = CAST(:o AS uuid) AND statut = 'DISPONIBLE'", ['o' => $offre]);
            $this->connexion->executeStatement('DELETE FROM import_cles WHERE id NOT IN (SELECT import_cles_id FROM cle_licence WHERE import_cles_id IS NOT NULL) AND fichier_nom LIKE :p', ['p' => 'cles-test-%']);
            $this->connexion->executeStatement('DELETE FROM offre WHERE id = CAST(:o AS uuid) AND NOT EXISTS (SELECT 1 FROM cle_licence WHERE offre_id = CAST(:o AS uuid)) AND NOT EXISTS (SELECT 1 FROM ligne_commande WHERE offre_id = CAST(:o AS uuid))', ['o' => $offre]);
            // Offre gardée par une ligne de commande (historique non supprimable) : retirée de la vente
            $this->connexion->executeStatement('UPDATE offre SET actif = false WHERE id = CAST(:o AS uuid)', ['o' => $offre]);
        }
        $this->offresCreees = [];
        parent::tearDown();
    }

    /** Nouvelle offre en vente, sans aucune clé. */
    protected function creerOffre(bool $active = true, bool $pegi18 = false, string $prix = '19.99'): string
    {
        $offre = (string) $this->connexion->fetchOne(
            <<<'SQL'
                INSERT INTO offre (prix_ttc, actif, jeu_id, plateforme_id)
                SELECT CAST(:prix AS numeric), :actif, j.id, p.id
                  FROM jeu j CROSS JOIN plateforme p
                 WHERE NOT EXISTS (SELECT 1 FROM offre o WHERE o.jeu_id = j.id AND o.plateforme_id = p.id AND o.actif)
                   AND (j.pegi >= 18) = :pegi18
                 ORDER BY j.slug, p.code
                 LIMIT 1
                RETURNING id::text
                SQL,
            ['actif' => $active ? 'true' : 'false', 'pegi18' => $pegi18 ? 'true' : 'false', 'prix' => $prix],
        );
        self::assertNotSame('', $offre, 'une combinaison jeu × plateforme libre doit exister');
        $this->offresCreees[] = $offre;

        return $offre;
    }

    protected function importer(string $offreId, string $contenu, ?string $jeton, string $nomFichier = 'cles-test-lot.csv'): void
    {
        $fichier = tempnam(sys_get_temp_dir(), 'cles');
        file_put_contents($fichier, $contenu);
        $serveur = null === $jeton ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$jeton];
        $this->client->request(
            'POST',
            '/api/admin/offres/'.$offreId.'/cles',
            files: ['fichier' => new \Symfony\Component\HttpFoundation\File\UploadedFile($fichier, $nomFichier, 'text/csv', null, true)],
            server: $serveur,
        );
    }

    protected function reveler(string $cleId, ?string $jeton): void
    {
        $this->json('POST', '/api/cles/'.$cleId.'/revelation', jeton: $jeton);
    }

    /** @return array{id: string, email: string} client dédié aux tests de clés (jamais supprimé : il a des commandes) */
    protected function creerAcheteur(): array
    {
        $email = 'cles-test-'.bin2hex(random_bytes(5)).'@example.test';
        $repository = static::getContainer()->get(UtilisateurRepository::class);
        self::assertInstanceOf(UtilisateurRepository::class, $repository);

        return ['id' => $repository->creerClient($email, FabriqueCompte::hash(self::MOT_DE_PASSE), 'Léa', 'Test', 'fr'), 'email' => $email];
    }

    /**
     * Commande d'un client avec une clé chiffrée par le vrai service (secrets de .env.test).
     *
     * @param string|null $chiffreForce chiffré à stocker à la place du vrai (clé altérée)
     *
     * @return string identifiant de la clé
     */
    protected function commandeAvecCle(string $utilisateurId, string $statutCommande, string $valeur, ?string $chiffreForce = null): string
    {
        $offre = (string) $this->connexion->fetchOne('SELECT id::text FROM offre WHERE actif ORDER BY cree_le, id LIMIT 1');
        $commande = (string) $this->connexion->fetchOne(
            <<<'SQL'
                INSERT INTO commande (statut, montant_total, confirme_majeur, payee_le, expire_le, utilisateur_id, langue_code)
                VALUES (:statut, 19.99, true, CASE WHEN :statut IN ('PAYEE', 'REMBOURSEE') THEN now() END,
                        now() + interval '15 minutes', CAST(:u AS uuid), 'fr')
                RETURNING id::text
                SQL,
            ['statut' => $statutCommande, 'u' => $utilisateurId],
        );
        $ligne = (string) $this->connexion->fetchOne(
            'INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (1, 19.99, CAST(:c AS uuid), CAST(:o AS uuid)) RETURNING id::text',
            ['c' => $commande, 'o' => $offre],
        );
        $chiffrement = static::getContainer()->get(ChiffrementCles::class);
        self::assertInstanceOf(ChiffrementCles::class, $chiffrement);
        $cle = CleNormalisee::depuis($valeur);
        self::assertNotNull($cle);

        return (string) $this->connexion->fetchOne(
            <<<'SQL'
                INSERT INTO cle_licence (cle_chiffree, empreinte, suffixe, statut, reservee_le, offre_id, ligne_commande_id)
                VALUES (:chiffre, :empreinte, :suffixe, :statut, now(), CAST(:o AS uuid), CAST(:l AS uuid))
                RETURNING id::text
                SQL,
            [
                'chiffre' => $chiffreForce ?? $chiffrement->chiffrer($cle, $offre),
                'empreinte' => $chiffrement->empreinte($cle),
                'suffixe' => $cle->suffixe(),
                'statut' => \in_array($statutCommande, ['PAYEE', 'REMBOURSEE'], true) ? 'VENDUE' : 'RESERVEE',
                'o' => $offre,
                'l' => $ligne,
            ],
            ['chiffre' => \Doctrine\DBAL\ParameterType::BINARY, 'empreinte' => \Doctrine\DBAL\ParameterType::BINARY],
        );
    }

    /**
     * Met $nombre clés DISPONIBLES sur l'offre, chiffrées par le vrai service.
     *
     * @return list<string> valeurs en clair, dans l'ordre d'import
     */
    protected function stocker(string $offreId, int $nombre): array
    {
        $chiffrement = static::getContainer()->get(ChiffrementCles::class);
        self::assertInstanceOf(ChiffrementCles::class, $chiffrement);
        $valeurs = [];
        for ($i = 0; $i < $nombre; ++$i) {
            $cle = CleNormalisee::depuis($valeurs[] = self::nouvelleCle());
            self::assertNotNull($cle);
            $this->connexion->executeStatement(
                'INSERT INTO cle_licence (cle_chiffree, empreinte, suffixe, offre_id, importee_le) VALUES (:c, :e, :s, CAST(:o AS uuid), now() + make_interval(secs => :i))',
                ['c' => $chiffrement->chiffrer($cle, $offreId), 'e' => $chiffrement->empreinte($cle), 's' => $cle->suffixe(), 'o' => $offreId, 'i' => $i],
                ['c' => \Doctrine\DBAL\ParameterType::BINARY, 'e' => \Doctrine\DBAL\ParameterType::BINARY],
            );
        }

        return $valeurs;
    }

    /** Clé de licence aléatoire, unique d'un test à l'autre. */
    protected static function nouvelleCle(): string
    {
        return implode('-', str_split(strtoupper(bin2hex(random_bytes(10))), 5));
    }
}
