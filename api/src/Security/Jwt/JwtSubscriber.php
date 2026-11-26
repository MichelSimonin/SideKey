<?php

declare(strict_types=1);

namespace App\Security\Jwt;

use App\Service\Auth\RevocationJwt;
use App\Service\Auth\TypeJeton;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTDecodedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTFailureEventInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Branche le cycle de vie des jetons (révocation, portée) et le contrat d'erreur de l'API sur LexikJWT.
 *
 *  - un JWT n'est accepté par le pare-feu que s'il est de la portée de la route (« access », ou « a2f » sur /api/connexion/a2f), porte un sujet (UUID), un jti et une famille, et
 *    n'a été révoqué ni individuellement (jwt:deny:{jti}) ni avec sa session ;
 *  - si Redis ne répond pas, le jeton est refusé : on ne peut pas prouver qu'il n'est pas révoqué
 *    (Fail Securely, ADR-0003) ;
 *  - toute authentification qui échoue (jeton absent, falsifié, expiré, révoqué) répond par le même 401
 *    générique et traduit : rien n'indique pourquoi.
 */
final class JwtSubscriber implements EventSubscriberInterface
{
    public const CHEMIN_A2F = '/api/connexion/a2f';
    private const SUJET = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
    private const JTI = '/^[0-9a-f]{32}$/';
    private const FAMILLE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly RevocationJwt $revocation,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requetes,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_DECODED => 'surJetonDecode',
            Events::JWT_NOT_FOUND => 'surEchec',
            Events::JWT_INVALID => 'surEchec',
            Events::JWT_EXPIRED => 'surEchec',
        ];
    }

    public function surJetonDecode(JWTDecodedEvent $evenement): void
    {
        $charge = $evenement->getPayload();
        $sujet = $charge['sub'] ?? null;
        $jti = $charge['jti'] ?? null;
        $famille = $charge['fam'] ?? null;

        if ($this->portee()->value !== ($charge['typ'] ?? null)   // chaque route n'accepte que la portée prévue pour elle
            || !\is_string($sujet) || 1 !== preg_match(self::SUJET, $sujet)
            || !\is_string($jti) || 1 !== preg_match(self::JTI, $jti)
            || !\is_string($famille) || 1 !== preg_match(self::FAMILLE, $famille)) {
            $evenement->markAsInvalid();

            return;
        }

        try {
            if ($this->revocation->estRevoque($jti, $famille)) {
                $evenement->markAsInvalid();
            }
        } catch (\Throwable $erreur) {
            $this->logger->error('Liste de révocation des jetons indisponible : jeton refusé', ['exception' => $erreur]);
            $evenement->markAsInvalid();
        }
    }

    /**
     * Portée exigée par la route en cours : le jeton intermédiaire (« a2f ») n'ouvre que les routes de la seconde étape
     * (/api/connexion/a2f…), le jeton d'accès (« access ») n'ouvre pas ces routes mais toutes les autres. Aucun jeton
     * n'est donc valable partout : le jeton intermédiaire ne permet jamais d'atteindre l'API sans second facteur.
     */
    private function portee(): TypeJeton
    {
        $chemin = $this->requetes->getCurrentRequest()?->getPathInfo() ?? '';

        return $chemin === self::CHEMIN_A2F || str_starts_with($chemin, self::CHEMIN_A2F.'/') ? TypeJeton::A2f : TypeJeton::Acces;
    }

    public function surEchec(JWTFailureEventInterface $evenement): void
    {
        $reponse = new JsonResponse(['message' => $this->translator->trans('error.unauthorized', [], 'errors')], Response::HTTP_UNAUTHORIZED);
        $reponse->headers->set('Cache-Control', 'no-store');
        $reponse->headers->set('WWW-Authenticate', 'Bearer');
        $reponse->headers->set('Content-Language', $this->translator->getLocale());
        $reponse->setVary('Accept-Language', false);
        $evenement->setResponse($reponse);
    }
}
