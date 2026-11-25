<?php

declare(strict_types=1);

namespace App\Service\Panier;

/**
 * Identifie un panier et fixe sa durée de vie (CDC §9.8, US-10) :
 *  - visiteur : cart:anon:{uuid}, 24 h, uuid tiré d'un cookie ;
 *  - client   : cart:{userId}, 7 jours (branché avec l'authentification, F-03).
 */
final class PanierId
{
    public const TTL_VISITEUR = 86_400;
    public const TTL_CLIENT = 604_800;

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(
        public readonly string $cle,
        public readonly int $ttl,
    ) {
    }

    public static function visiteur(string $uuid): self
    {
        return new self('cart:anon:'.self::uuidValide($uuid), self::TTL_VISITEUR);
    }

    public static function client(string $utilisateurId): self
    {
        return new self('cart:'.self::uuidValide($utilisateurId), self::TTL_CLIENT);
    }

    public static function estUuid(string $valeur): bool
    {
        return 1 === preg_match(self::UUID, strtolower($valeur));
    }

    /** La valeur vient d'un cookie ou d'un jeton : jamais concaténée dans une clé sans contrôle. */
    private static function uuidValide(string $uuid): string
    {
        $uuid = strtolower($uuid);
        if (!self::estUuid($uuid)) {
            throw new \InvalidArgumentException('Identifiant de panier invalide.');
        }

        return $uuid;
    }
}
