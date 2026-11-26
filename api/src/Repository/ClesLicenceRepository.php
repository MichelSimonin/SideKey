<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Cles\CleAImporter;
use App\Service\Cles\CleRevelable;
use App\Service\Cles\ClesLicence;
use App\Service\Cles\RapportImport;
use App\Service\Panier\PanierId;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Clés de licence et imports (DBAL, SQL paramétré, compte sk_app : INSERT et UPDATE, jamais DELETE).
 *
 * Doublons : la contrainte uq_cle_empreinte tranche, y compris entre deux imports simultanés.
 * « ON CONFLICT (empreinte) DO NOTHING » ignore la ligne au lieu d'annuler tout le lot.
 */
final class ClesLicenceRepository implements ClesLicence
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    public function offreActive(string $offreId): ?string
    {
        if (!PanierId::estUuid($offreId)) {
            return null;
        }
        $slug = $this->connexion->fetchOne(
            'SELECT j.slug FROM offre o JOIN jeu j ON j.id = o.jeu_id WHERE o.id = CAST(:id AS uuid) AND o.actif',
            ['id' => strtolower($offreId)],
        );

        return false === $slug ? null : (string) $slug;
    }

    public function ouvrirImport(string $nomFichier, string $utilisateurId): string
    {
        return (string) $this->connexion->fetchOne(
            <<<'SQL'
                INSERT INTO import_cles (fichier_nom, statut, utilisateur_id)
                VALUES (:fichier, 'EN_COURS', CAST(:utilisateur AS uuid))
                RETURNING id::text
                SQL,
            ['fichier' => $nomFichier, 'utilisateur' => $utilisateurId],
        );
    }

    public function insererLot(string $offreId, string $importId, array $cles): array
    {
        if ([] === $cles) {
            return [];
        }
        $valeurs = [];
        $parametres = ['offre' => strtolower($offreId), 'import' => $importId];
        $types = [];
        $lignesParEmpreinte = [];
        foreach ($cles as $i => $cle) {
            $valeurs[] = "(:c$i, :e$i, :s$i, CAST(:offre AS uuid), CAST(:import AS uuid))";
            $parametres["c$i"] = $cle->chiffre;
            $parametres["e$i"] = $cle->empreinte;
            $parametres["s$i"] = $cle->suffixe;
            $types["c$i"] = ParameterType::BINARY;
            $types["e$i"] = ParameterType::BINARY;
            $lignesParEmpreinte[bin2hex($cle->empreinte)] = $cle->ligne;
        }

        $inserees = $this->connexion->transactional(fn (Connection $c): array => $c->fetchFirstColumn(
            'INSERT INTO cle_licence (cle_chiffree, empreinte, suffixe, offre_id, import_cles_id) VALUES '
            .implode(', ', $valeurs)
            .' ON CONFLICT (empreinte) DO NOTHING RETURNING encode(empreinte, \'hex\')',
            $parametres,
            $types,
        ));

        return array_values(array_map(static fn (mixed $hex): int => $lignesParEmpreinte[(string) $hex], $inserees));
    }

    public function cloreImport(RapportImport $rapport): void
    {
        $this->connexion->executeStatement(
            <<<'SQL'
                UPDATE import_cles
                   SET nb_lignes = :lignes, nb_acceptees = :acceptees, nb_doublons = :doublons,
                       nb_erreurs = :erreurs, statut = :statut
                 WHERE id = CAST(:id AS uuid)
                SQL,
            [
                'id' => $rapport->id,
                'lignes' => $rapport->nbLignes(),
                'acceptees' => $rapport->nbAcceptees(),
                'doublons' => $rapport->nbDoublons(),
                'erreurs' => $rapport->nbErreurs(),
                'statut' => $rapport->statut(),
            ],
        );
    }

    public function cleDuProprietaire(string $cleId, string $utilisateurId): ?CleRevelable
    {
        if (!PanierId::estUuid($cleId)) {
            return null;   // jamais d'erreur de conversion uuid côté base pour une valeur forgée
        }
        // Le propriétaire fait partie de la condition : la clé d'un autre client n'est même pas lue (anti-IDOR)
        $ligne = $this->connexion->fetchAssociative(
            <<<'SQL'
                SELECT k.id::text AS id, k.offre_id::text AS offre_id, k.cle_chiffree, k.statut AS statut_cle,
                       k.revelee_le, c.utilisateur_id::text AS proprietaire_id, c.statut AS statut_commande
                  FROM cle_licence k
                  JOIN ligne_commande l ON l.id = k.ligne_commande_id
                  JOIN commande c       ON c.id = l.commande_id
                 WHERE k.id = CAST(:cle AS uuid) AND c.utilisateur_id = CAST(:utilisateur AS uuid)
                SQL,
            ['cle' => strtolower($cleId), 'utilisateur' => $utilisateurId],
        );
        if (false === $ligne) {
            return null;
        }

        return new CleRevelable(
            (string) $ligne['id'],
            (string) $ligne['offre_id'],
            self::octets($ligne['cle_chiffree']),
            (string) $ligne['proprietaire_id'],
            (string) $ligne['statut_commande'],
            (string) $ligne['statut_cle'],
            null === $ligne['revelee_le'] ? null : new \DateTimeImmutable((string) $ligne['revelee_le']),
        );
    }

    public function marquerRevelee(string $cleId): \DateTimeImmutable
    {
        // Seule mise à jour qu'admet trg_cle_licence_protection sur une clé vendue : revelee_le de NULL à une date
        $this->connexion->executeStatement(
            'UPDATE cle_licence SET revelee_le = now(), modifie_le = now() WHERE id = CAST(:id AS uuid) AND revelee_le IS NULL',
            ['id' => $cleId],
        );

        return new \DateTimeImmutable((string) $this->connexion->fetchOne(
            'SELECT revelee_le FROM cle_licence WHERE id = CAST(:id AS uuid)',
            ['id' => $cleId],
        ));
    }

    /** pdo_pgsql renvoie une colonne BYTEA sous forme de flux. */
    private static function octets(mixed $valeur): string
    {
        if (\is_resource($valeur)) {
            return (string) stream_get_contents($valeur);
        }

        return (string) $valeur;
    }
}
