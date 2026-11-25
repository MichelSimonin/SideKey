<?php

declare(strict_types=1);

namespace App\Security\Http;

use App\Service\Auth\StockRefresh;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookies de la session (CDC §11.4) :
 *  - sk_refresh : jeton de rafraîchissement opaque, HttpOnly, Secure, SameSite=Strict, limité à
 *    /api/token/refresh (le navigateur ne l'envoie à aucune autre route) ;
 *  - sk_csrf    : jeton de double soumission, lisible par la SPA (qui le renvoie dans X-CSRF-Token),
 *    Secure, SameSite=Strict. Il ne donne aucun accès à lui seul.
 */
final class CookiesSession
{
    public const REFRESH = 'sk_refresh';
    public const CSRF = 'sk_csrf';
    public const CHEMIN_REFRESH = '/api/token/refresh';

    public function __construct(private readonly ProtectionCsrf $csrf)
    {
    }

    /** Pose les deux cookies pour un nouveau jeton de rafraîchissement. */
    public function poser(Response $reponse, string $jetonRefresh): void
    {
        $expiration = time() + StockRefresh::TTL;
        $reponse->headers->setCookie(
            Cookie::create(self::REFRESH, $jetonRefresh, $expiration, self::CHEMIN_REFRESH, null, true, true, false, Cookie::SAMESITE_STRICT),
        );
        $reponse->headers->setCookie(
            Cookie::create(self::CSRF, $this->csrf->jetonPour($jetonRefresh), $expiration, '/', null, true, false, false, Cookie::SAMESITE_STRICT),
        );
    }

    /** Efface les deux cookies (déconnexion, session invalide) : mêmes attributs, expiration passée. */
    public function effacer(Response $reponse): void
    {
        $reponse->headers->setCookie(Cookie::create(self::REFRESH, '', 1, self::CHEMIN_REFRESH, null, true, true, false, Cookie::SAMESITE_STRICT));
        $reponse->headers->setCookie(Cookie::create(self::CSRF, '', 1, '/', null, true, false, false, Cookie::SAMESITE_STRICT));
    }

    public function lireRefresh(Request $requete): ?string
    {
        $valeur = $requete->cookies->get(self::REFRESH);

        return \is_string($valeur) && '' !== $valeur ? $valeur : null;
    }
}
