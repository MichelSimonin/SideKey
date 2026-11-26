<?php

declare(strict_types=1);

namespace App\Service\A2f;

/** Le secret TOTP stocké n'est pas déchiffrable. Jamais de valeur dans le message ; le service traite le code comme faux. */
final class SecretTotpIllisibleException extends \RuntimeException
{
}
