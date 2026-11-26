<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/** Réponse du prestataire : statut (ACCEPTE, REFUSE, ERREUR) et code de retour, sans aucune donnée de carte. */
final class ResultatPaiement
{
    public const ACCEPTE = 'ACCEPTE';
    public const REFUSE = 'REFUSE';
    public const ERREUR = 'ERREUR';

    private function __construct(
        public readonly string $statut,
        public readonly string $codeRetour,
    ) {
    }

    public static function accepte(): self
    {
        return new self(self::ACCEPTE, 'ok');
    }

    /** Refus du prestataire : carte refusée, fonds insuffisants, carte inconnue. */
    public static function refuse(string $codeRetour): self
    {
        return new self(self::REFUSE, $codeRetour);
    }

    /** Pas de réponse exploitable : délai dépassé, prestataire en erreur. */
    public static function erreur(string $codeRetour): self
    {
        return new self(self::ERREUR, $codeRetour);
    }

    public function estAccepte(): bool
    {
        return self::ACCEPTE === $this->statut;
    }
}
