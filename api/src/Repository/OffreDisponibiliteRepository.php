<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Panier\CatalogueOffres;
use App\Service\Panier\OffreDisponibilite;
use App\Service\Panier\PanierId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lecture du stock et du prix des offres pour le panier (requête SQL paramétrée, lecture seule).
 * Le stock compte uniquement les clés DISPONIBLE : une clé réservée par un autre client n'est
 * pas proposée.
 */
final class OffreDisponibiliteRepository implements CatalogueOffres
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    public function disponibilites(array $offreIds): array
    {
        // Seuls des identifiants bien formés atteignent la base (sinon erreur de conversion uuid).
        $offreIds = array_values(array_unique(array_filter($offreIds, PanierId::estUuid(...))));
        if ([] === $offreIds) {
            return [];
        }

        $lignes = $this->connexion->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id::text                          AS offre_id,
                       j.titre,
                       p.code                              AS plateforme,
                       (o.prix_ttc * 100)::integer         AS prix_centimes,
                       o.actif,
                       count(k.id)                         AS stock
                  FROM offre o
                  JOIN jeu j        ON j.id = o.jeu_id
                  JOIN plateforme p ON p.id = o.plateforme_id
                  LEFT JOIN cle_licence k ON k.offre_id = o.id AND k.statut = 'DISPONIBLE'
                 WHERE o.id::text IN (:ids)
                 GROUP BY o.id, j.titre, p.code
                SQL,
            ['ids' => array_map('strtolower', $offreIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        $offres = [];
        foreach ($lignes as $ligne) {
            $offres[(string) $ligne['offre_id']] = new OffreDisponibilite(
                (string) $ligne['offre_id'],
                (string) $ligne['titre'],
                (string) $ligne['plateforme'],
                (int) $ligne['prix_centimes'],
                (int) $ligne['stock'],
                (bool) $ligne['actif'],
            );
        }

        return $offres;
    }
}
