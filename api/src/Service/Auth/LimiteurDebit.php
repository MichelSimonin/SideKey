<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\TropDeTentativesException;

/** Compteur de tentatives à fenêtre fixe (CDC §9.8 : rl:login:{ip}). Implémentation : Redis, atomique. */
interface LimiteurDebit
{
    /**
     * Compte une tentative.
     *
     * @param string $cle             clé complète, construite par l'appelant à partir d'une valeur validée
     * @param int    $max             tentatives autorisées dans la fenêtre
     * @param int    $fenetreSecondes durée de la fenêtre, démarrée à la première tentative
     *
     * @throws TropDeTentativesException au-delà de $max (avec le délai avant la prochaine fenêtre)
     */
    public function enregistrerTentative(string $cle, int $max, int $fenetreSecondes): void;
}
