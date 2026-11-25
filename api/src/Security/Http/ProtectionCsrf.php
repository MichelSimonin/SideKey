<?php

declare(strict_types=1);

namespace App\Security\Http;

use App\Exception\OrigineRefuseeException;
use App\Exception\SessionInvalideException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Protection CSRF de la seule route à cookie, POST /api/token/refresh (CDC §11.4, ADR-0003).
 * Les autres routes lisent l'en-tête Authorization, que le navigateur n'ajoute jamais tout seul.
 *
 * Trois barrières, toutes obligatoires :
 *  1. SameSite=Strict sur le cookie (CookiesSession) ;
 *  2. en-tête Origin présent et conforme à CORS_ALLOW_ORIGIN : un navigateur l'ajoute à tout POST
 *     inter-site, un attaquant ne peut pas le falsifier ;
 *  3. double soumission signée : l'en-tête X-CSRF-Token doit être égal au cookie sk_csrf, et ce jeton doit
 *     être le HMAC-SHA256 du jeton de rafraîchissement présenté. Un cookie sk_csrf injecté par un tiers
 *     (sous-domaine, réseau) ne passe pas : il n'est pas lié au refresh.
 *
 * Les comparaisons sont à temps constant (hash_equals).
 */
final class ProtectionCsrf
{
    public const EN_TETE = 'X-CSRF-Token';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%env(CORS_ALLOW_ORIGIN)%')]
        private readonly string $originesAutorisees,
    ) {
    }

    /** Jeton CSRF lié à un jeton de rafraîchissement (base64url, 43 caractères). */
    public function jetonPour(string $jetonRefresh): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $jetonRefresh, $this->secret, true)), '+/', '-_'), '=');
    }

    /**
     * @throws OrigineRefuseeException  origine non autorisée ou jeton CSRF absent / invalide (403)
     * @throws SessionInvalideException aucun jeton de rafraîchissement dans la requête (401)
     */
    public function verifier(Request $requete): void
    {
        if (!$this->origineAutorisee($requete->headers->get('Origin'))) {
            throw new OrigineRefuseeException();
        }

        $refresh = $requete->cookies->get(CookiesSession::REFRESH);
        if (!\is_string($refresh) || '' === $refresh) {
            throw new SessionInvalideException();
        }

        $entete = $requete->headers->get(self::EN_TETE);
        $cookie = $requete->cookies->get(CookiesSession::CSRF);
        if (!\is_string($entete) || '' === $entete || !\is_string($cookie)
            || !hash_equals($cookie, $entete) || !hash_equals($this->jetonPour($refresh), $entete)) {
            throw new OrigineRefuseeException();
        }
    }

    private function origineAutorisee(?string $origine): bool
    {
        if (null === $origine || '' === $origine) {
            return false;   // Fail Securely : pas d'Origin, pas de rafraîchissement
        }

        return 1 === preg_match('#'.str_replace('#', '\#', $this->originesAutorisees).'#iD', $origine);
    }
}
