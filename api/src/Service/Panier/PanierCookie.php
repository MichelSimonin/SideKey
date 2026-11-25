<?php

declare(strict_types=1);

namespace App\Service\Panier;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Identifie le panier d'un visiteur par un cookie opaque (uuid aléatoire, aucune donnée personnelle).
 * HttpOnly : illisible en JavaScript ; Secure ; SameSite=Strict : jamais envoyé par un autre site.
 * Quand l'authentification arrivera (F-03), le panier d'un client connecté sera cart:{userId}
 * et la fusion avec le panier visiteur relève de P-10.
 */
final class PanierCookie
{
    public const NOM = 'sk_panier';

    public function lire(Request $requete): ?PanierId
    {
        $valeur = $requete->cookies->get(self::NOM);

        return \is_string($valeur) && PanierId::estUuid($valeur) ? PanierId::visiteur($valeur) : null;
    }

    /** @return array{0: PanierId, 1: ?Cookie} le cookie est à poser s'il vient d'être créé */
    public function lireOuCreer(Request $requete): array
    {
        $panier = $this->lire($requete);
        if (null !== $panier) {
            return [$panier, $this->cookie((string) $requete->cookies->get(self::NOM))];
        }
        $uuid = self::uuidV4();

        return [PanierId::visiteur($uuid), $this->cookie($uuid)];
    }

    /** uuid v4 tiré de random_bytes (CSPRNG) : impossible de deviner le panier d'un autre visiteur. */
    private static function uuidV4(): string
    {
        $octets = random_bytes(16);
        $octets[6] = \chr((\ord($octets[6]) & 0x0F) | 0x40);
        $octets[8] = \chr((\ord($octets[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($octets), 4));
    }

    /** Le cookie suit la durée de vie du panier (TTL glissant de 24 h). */
    private function cookie(string $uuid): Cookie
    {
        return Cookie::create(self::NOM)
            ->withValue(strtolower($uuid))
            ->withExpires(time() + PanierId::TTL_VISITEUR)
            ->withPath('/api')
            ->withSecure(true)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }
}
