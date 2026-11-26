<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Verrou d'idempotence d'une clé de paiement (CDC §9.8 : idem:{clé}, 24 h). Implémentation : Redis, SET NX atomique.
 * Première barrière contre le double envoi simultané ; la base (clé unique et index des paiements actifs) reste la
 * dernière, et la seule durable.
 */
interface Idempotence
{
    /** Réserve la clé : true pour le premier appelant, false si elle est déjà réservée. */
    public function reserver(string $empreinte): bool;

    /** Rend la clé quand rien n'a été écrit (refus avant le prestataire) : le client peut la rejouer. */
    public function liberer(string $empreinte): void;
}
