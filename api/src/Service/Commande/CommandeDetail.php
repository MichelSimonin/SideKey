<?php

declare(strict_types=1);

namespace App\Service\Commande;

/**
 * Détail d'une commande pour son client (US-33, US-35). L'identifiant interne ne sort pas de l'API :
 * le client ne connaît que la référence. Les clés ne sont listées que pour une commande PAYEE, sans
 * leur valeur : la révélation passe par POST /api/cles/{id}/revelation (F-08).
 */
final class CommandeDetail
{
    /** @param list<LigneCommandeDetail> $lignes */
    public function __construct(
        public readonly string $id,
        public readonly string $reference,
        public readonly string $statut,
        public readonly int $montantCentimes,
        public readonly \DateTimeImmutable $creeLe,
        public readonly \DateTimeImmutable $expireLe,
        public readonly ?\DateTimeImmutable $payeeLe,
        public readonly array $lignes,
        public readonly ?int $secondesRestantes = null,
    ) {
    }

    public function enAttenteDePaiement(): bool
    {
        return 'EN_ATTENTE_PAIEMENT' === $this->statut;
    }

    public function avecSecondesRestantes(?int $secondes): self
    {
        return new self($this->id, $this->reference, $this->statut, $this->montantCentimes, $this->creeLe,
            $this->expireLe, $this->payeeLe, $this->lignes, $secondes);
    }
}
