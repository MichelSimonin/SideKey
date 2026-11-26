<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\CodeA2fRequest;
use App\Dto\Response\SessionResponse;
use App\Exception\SessionInvalideException;
use App\Security\Http\CookiesSession;
use App\Security\Http\QrCode;
use App\Security\Utilisateur;
use App\Service\A2f\A2fService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Seconde étape de la connexion (US-21, US-22, CDC §11.4). Couche présentation : validation du DTO, cookies, codes HTTP.
 *
 * Ces trois routes n'acceptent QUE le jeton intermédiaire remis par POST /api/connexion (portée « a2f », 5 min) : le
 * pare-feu refuse le jeton d'accès ici, et le jeton intermédiaire partout ailleurs (JwtSubscriber). Elles ne sont pas
 * publiques : sans jeton intermédiaire valide, 401.
 */
final class A2fController
{
    public function __construct(
        private readonly A2fService $a2f,
        private readonly CookiesSession $cookies,
    ) {
    }

    /** Étape « code » : l'A2F est configurée, l'utilisateur saisit un code TOTP ou un code de secours. */
    #[Route('/api/connexion/a2f', name: 'api_connexion_a2f', methods: ['POST'])]
    public function verifier(Request $requete, #[CurrentUser] Utilisateur $utilisateur, #[MapRequestPayload(acceptFormat: 'json')] CodeA2fRequest $corps): JsonResponse
    {
        [$jti, $expiration] = $this->jetonIntermediaire($utilisateur);

        $session = $this->a2f->valider($utilisateur->compte, $corps->code, $requete->getClientIp() ?? '', $jti, $expiration);
        $reponse = self::json(SessionResponse::depuis($session));
        $this->cookies->poser($reponse, $session->jetonRefresh);

        return $reponse;
    }

    /** Étape « enrôlement » (1/2) : un nouveau secret, en QR code et en texte. Chaque appel remplace le secret en attente. */
    #[Route('/api/connexion/a2f/enrolement', name: 'api_connexion_a2f_enrolement', methods: ['POST'])]
    public function demarrerEnrolement(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $this->jetonIntermediaire($utilisateur);
        $enrolement = $this->a2f->demarrerEnrolement($utilisateur->compte);

        return self::json([
            'secret' => $enrolement->secret,
            'uri' => $enrolement->uri,
            'qrCode' => QrCode::uriDonnees($enrolement->uri),
        ]);
    }

    /** Étape « enrôlement » (2/2) : premier code, activation, codes de secours (montrés une seule fois) et session. */
    #[Route('/api/connexion/a2f/enrolement/confirmation', name: 'api_connexion_a2f_confirmation', methods: ['POST'])]
    public function confirmerEnrolement(Request $requete, #[CurrentUser] Utilisateur $utilisateur, #[MapRequestPayload(acceptFormat: 'json')] CodeA2fRequest $corps): JsonResponse
    {
        [$jti, $expiration] = $this->jetonIntermediaire($utilisateur);

        $activation = $this->a2f->confirmerEnrolement($utilisateur->compte, $corps->code, $requete->getClientIp() ?? '', $jti, $expiration);
        $reponse = self::json(SessionResponse::depuis($activation->session) + ['codesSecours' => $activation->codesSecours], Response::HTTP_CREATED);
        $this->cookies->poser($reponse, $activation->session->jetonRefresh);

        return $reponse;
    }

    /**
     * @return array{0: string, 1: int} jti et expiration du jeton intermédiaire présenté
     */
    private function jetonIntermediaire(Utilisateur $utilisateur): array
    {
        if (null === $utilisateur->jti || null === $utilisateur->expiration) {
            throw new SessionInvalideException();
        }

        return [$utilisateur->jti, $utilisateur->expiration];
    }

    /** @param array<string, mixed> $donnees */
    private static function json(array $donnees, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($donnees, $statut);
        $reponse->headers->set('Cache-Control', 'no-store');   // secret, codes de secours et jetons : jamais en cache

        return $reponse;
    }
}
