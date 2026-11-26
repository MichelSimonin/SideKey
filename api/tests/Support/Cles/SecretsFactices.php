<?php

declare(strict_types=1);

namespace App\Tests\Support\Cles;

use App\Infrastructure\Crypto\ChiffreurClesAesGcm;

/** Secrets factices des tests unitaires (32 octets chacun), sans lien avec ceux d'un environnement. */
final class SecretsFactices
{
    public static function chiffrement(): string
    {
        return base64_encode(str_repeat('A', 32));
    }

    public static function empreinte(): string
    {
        return base64_encode(str_repeat('B', 32));
    }

    public static function chiffreur(): ChiffreurClesAesGcm
    {
        return new ChiffreurClesAesGcm(self::chiffrement(), self::empreinte());
    }
}
