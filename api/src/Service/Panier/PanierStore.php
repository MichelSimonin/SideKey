<?php

declare(strict_types=1);

namespace App\Service\Panier;

/**
 * Stockage du panier (implémentation : Infrastructure/Redis/RedisPanierStore).
 * Le panier ne contient que des identifiants d'offre et des quantités : prix, titres et stock
 * sont toujours relus dans PostgreSQL (RG-11, ADR 0002).
 */
interface PanierStore
{
    /** @return array<string, int> quantité par identifiant d'offre */
    public function lire(PanierId $panier): array;

    /** Fixe la quantité d'une offre et repousse l'expiration du panier. */
    public function definir(PanierId $panier, string $offreId, int $quantite): void;

    /** Retire une offre et repousse l'expiration du panier. */
    public function retirer(PanierId $panier, string $offreId): void;

    public function vider(PanierId $panier): void;
}
