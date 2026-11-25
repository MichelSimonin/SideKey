<?php

declare(strict_types=1);

namespace App\Service\Auth;

enum Rotation
{
    /** Le jeton était actif : il est consommé et remplacé. */
    case Reussie;
    /** Le jeton avait déjà été consommé : vol probable, la famille entière est révoquée. */
    case Reutilisation;
    /** Jeton inconnu, expiré ou déjà révoqué. */
    case Inconnu;
}
