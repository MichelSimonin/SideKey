<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Service\Panier\LignePanier;
use App\Service\Panier\Panier;

/**
 * Contrat de sortie du panier. Les montants sont des chaînes décimales (« 29.99 ») calculées
 * côté serveur à partir des prix en base : le front affiche, il ne calcule pas (RG-11).
 */
final class PanierResponse
{
    /**
     * @return array{
     *     lignes: list<array{offreId: string, titre: string, plateforme: string, prixUnitaire: string,
     *                        quantite: int, quantiteMax: int, quantiteAjustee: bool, sousTotal: string}>,
     *     nombreArticles: int,
     *     total: string,
     *     offresRetirees: list<string>
     * }
     */
    public static function depuis(Panier $panier): array
    {
        return [
            'lignes' => array_map(static fn (LignePanier $l): array => [
                'offreId' => $l->offre->offreId,
                'titre' => $l->offre->titre,
                'plateforme' => $l->offre->plateforme,
                'prixUnitaire' => self::montant($l->offre->prixCentimes),
                'quantite' => $l->quantite,
                'quantiteMax' => $l->quantiteMax,
                'quantiteAjustee' => $l->quantiteAjustee,
                'sousTotal' => self::montant($l->sousTotalCentimes()),
            ], $panier->lignes),
            'nombreArticles' => $panier->nombreArticles(),
            'total' => self::montant($panier->totalCentimes()),
            'offresRetirees' => $panier->offresRetirees,
        ];
    }

    public static function montant(int $centimes): string
    {
        return \sprintf('%d.%02d', intdiv($centimes, 100), $centimes % 100);
    }
}
