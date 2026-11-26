<?php

declare(strict_types=1);

namespace App\Repository;

use App\Exception\CatalogueException;
use App\Exception\ConflitOffreException;
use App\Service\Catalogue\Gestion\Identifiant;
use App\Service\Catalogue\Gestion\OffresAdmin;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * Offres du back-office (SQL paramétré, DBAL). Le verrouillage optimiste est dans l'UPDATE lui-même : `AND version = :attendue`
 * puis `version = version + 1`, donc atomique sous concurrence (CDC §10.2, BX-14). Une offre n'est jamais supprimée : elle se
 * désactive, l'historique des commandes y fait référence.
 */
final class OffresAdminRepository implements OffresAdmin
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    public function creer(string $jeuId, string $plateformeId, string $prix, int $seuilAlerte): array
    {
        $jeuId = Identifiant::exiger($jeuId);
        $plateformeId = Identifiant::exiger($plateformeId);
        try {
            $id = (string) $this->connexion->fetchOne(
                'INSERT INTO offre (prix_ttc, seuil_alerte, actif, jeu_id, plateforme_id) VALUES (CAST(:prix AS numeric), :seuil, true, :jeu::uuid, :plateforme::uuid) RETURNING id::text',
                ['prix' => $prix, 'seuil' => $seuilAlerte, 'jeu' => $jeuId, 'plateforme' => $plateformeId],
                ['seuil' => ParameterType::INTEGER],
            );
        } catch (UniqueConstraintViolationException) {
            throw CatalogueException::doublon('error.offre_doublon');   // uq_offre_active : une seule offre active par jeu et plateforme (RG-01)
        } catch (ForeignKeyConstraintViolationException) {
            throw CatalogueException::introuvable();
        }

        return $this->resultat($id);
    }

    public function modifier(string $offreId, string $prix, int $seuilAlerte, bool $actif, int $versionAttendue): array
    {
        $offreId = Identifiant::exiger($offreId);
        try {
            $ligne = $this->connexion->fetchAssociative(
                <<<'SQL'
                    UPDATE offre SET prix_ttc = CAST(:prix AS numeric), seuil_alerte = :seuil, actif = :actif,
                                     version = version + 1, modifie_le = now()
                     WHERE id = :id::uuid AND version = :attendue
                    RETURNING id::text
                    SQL,
                ['prix' => $prix, 'seuil' => $seuilAlerte, 'actif' => $actif, 'id' => $offreId, 'attendue' => $versionAttendue],
                ['seuil' => ParameterType::INTEGER, 'actif' => ParameterType::BOOLEAN, 'attendue' => ParameterType::INTEGER],
            );
        } catch (UniqueConstraintViolationException) {
            throw CatalogueException::doublon('error.offre_doublon');   // réactivation d'une offre alors qu'une autre est active
        }

        if (false === $ligne) {
            // Aucune ligne modifiée : l'offre n'existe pas, ou sa version a changé (un collègue a écrit avant).
            $actuelle = $this->connexion->fetchOne('SELECT version FROM offre WHERE id = :id::uuid', ['id' => $offreId]);

            throw false === $actuelle ? CatalogueException::introuvable() : new ConflitOffreException((int) $actuelle);
        }

        return $this->resultat($offreId);
    }

    /** @return array{offre: \App\Service\Catalogue\Gestion\OffreAdmin, jeuSlug: string} */
    private function resultat(string $offreId): array
    {
        $offre = OffresLecture::parId($this->connexion, $offreId) ?? throw CatalogueException::introuvable();
        $slug = $this->connexion->fetchOne('SELECT j.slug FROM jeu j JOIN offre o ON o.jeu_id = j.id WHERE o.id = :id::uuid', ['id' => $offreId]);

        return ['offre' => $offre, 'jeuSlug' => (string) $slug];
    }
}
