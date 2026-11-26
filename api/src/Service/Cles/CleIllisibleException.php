<?php

declare(strict_types=1);

namespace App\Service\Cles;

/**
 * Un chiffré n'a pas pu être déchiffré : altéré en base, rattaché à une autre offre, ou chiffré avec une
 * autre clé maître. Erreur technique (500) : le message ne contient jamais de valeur de clé.
 */
final class CleIllisibleException extends \RuntimeException
{
}
