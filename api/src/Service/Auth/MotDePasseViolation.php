<?php

declare(strict_types=1);

namespace App\Service\Auth;

/** Raison pour laquelle un mot de passe est refusé ; la valeur sert de clé de traduction (domaine validators). */
enum MotDePasseViolation: string
{
    case TropCourt = 'password.too_short';
    case TropLong = 'password.too_long';
    case Courant = 'password.common';
    case ContientIdentifiant = 'password.contains_identity';
}
