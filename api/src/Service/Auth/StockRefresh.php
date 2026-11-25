<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Jetons de rafraîchissement actifs (CDC §9.8 : refresh:{jti}, TTL 7 j). Implémentation : Redis.
 * On ne manipule que l'empreinte SHA-256 du jeton : un export de Redis ne livre aucun jeton utilisable.
 * Une « famille » regroupe tous les jetons issus d'une même connexion.
 */
interface StockRefresh
{
    public const TTL = 604_800;   // 7 jours

    public function enregistrer(string $empreinte, string $utilisateurId, string $familleId): void;

    /**
     * Remplace, de façon atomique, un jeton par son successeur (usage unique avec rotation).
     * Un jeton déjà consommé révoque toute sa famille.
     */
    public function faireTourner(string $ancienneEmpreinte, string $nouvelleEmpreinte): ResultatRotation;

    /** Supprime tous les jetons actifs de la famille. */
    public function revoquerFamille(string $familleId): void;
}
