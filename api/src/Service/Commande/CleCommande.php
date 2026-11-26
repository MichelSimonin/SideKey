<?php

declare(strict_types=1);

namespace App\Service\Commande;

/** Clé vendue d'une commande payée : identifiant pour la révélation, jamais sa valeur. */
final class CleCommande
{
    public function __construct(
        public readonly string $id,
        public readonly ?\DateTimeImmutable $reveleeLe,
    ) {
    }
}
