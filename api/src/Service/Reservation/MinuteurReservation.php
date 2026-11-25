<?php

declare(strict_types=1);

namespace App\Service\Reservation;

/**
 * Minuteur de la réservation de 15 min (RG-12), affiché pendant le tunnel de commande.
 * Implémentation : Infrastructure/Redis/RedisMinuteurReservation (clé resa:{commandeId}).
 *
 * La base reste la référence : l'expiration réelle est appliquée par fn_liberer_reservations_expirees
 * (tâche planifiée chaque minute). Perdre Redis ne libère ni ne vend aucune clé (ADR 0002).
 */
interface MinuteurReservation
{
    public const DUREE_SECONDES = 900;

    /** Démarre le minuteur jusqu'à l'échéance de la commande (à appeler après le commit, F-06). */
    public function demarrer(string $commandeId, \DateTimeImmutable $expireLe): void;

    /** Secondes restantes, ou null si la réservation a expiré ou n'existe pas. */
    public function secondesRestantes(string $commandeId): ?int;

    /** Arrête le minuteur (paiement accepté, commande annulée). */
    public function arreter(string $commandeId): void;
}
