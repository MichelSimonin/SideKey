<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\ConnexionRequest;
use App\Dto\Request\InscriptionRequest;
use App\Dto\Response\SessionResponse;
use App\Dto\Response\UtilisateurResponse;
use App\Exception\SessionInvalideException;
use App\Security\Http\CookiesSession;
use App\Security\Http\ProtectionCsrf;
use App\Security\Utilisateur;
use App\Service\Auth\AuthentificationService;
use App\Service\Auth\InscriptionService;
use App\Service\Auth\SessionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Authentification (US-20, US-21, CDC §11.4). Couche présentation : validation des entrées par DTO, cookies,
 * codes HTTP ; aucune règle métier ici, tout passe par les services Auth.
 *
 * Routes publiques (voir access_control) : inscription, connexion et rafraîchissement. La déconnexion exige
 * un jeton d'accès valide.
 */
final class AuthController
{
    public function __construct(
        private readonly InscriptionService $inscriptions,
        private readonly AuthentificationService $authentification,
        private readonly SessionService $sessions,
        private readonly CookiesSession $cookies,
        private readonly ProtectionCsrf $csrf,
    ) {
    }

    #[Route('/api/inscription', name: 'api_inscription', methods: ['POST'])]
    public function inscription(Request $requete, #[MapRequestPayload(acceptFormat: 'json')] InscriptionRequest $corps): JsonResponse
    {
        $compte = $this->inscriptions->inscrire(
            $corps->email,
            $corps->motDePasse,
            $corps->prenom,
            $corps->nom,
            $corps->langue,
            $requete->getClientIp() ?? '',
        );

        return self::json(['utilisateur' => UtilisateurResponse::depuis($compte)], Response::HTTP_CREATED);
    }

    #[Route('/api/connexion', name: 'api_connexion', methods: ['POST'])]
    public function connexion(Request $requete, #[MapRequestPayload(acceptFormat: 'json')] ConnexionRequest $corps): JsonResponse
    {
        $resultat = $this->authentification->connecter($corps->email, $corps->motDePasse, $requete->getClientIp() ?? '');

        if ($resultat->exigeA2f()) {
            return self::json(SessionResponse::etapeA2f($resultat));
        }

        $session = $resultat->session ?? throw new \LogicException('Connexion réussie sans session.');
        $reponse = self::json(SessionResponse::depuis($session));
        $this->cookies->poser($reponse, $session->jetonRefresh);

        return $reponse;
    }

    /**
     * Seule route à cookie : Origin, double soumission signée et SameSite=Strict (ProtectionCsrf).
     * Le jeton reçu est consommé et remplacé (usage unique) ; sa réutilisation révoque toute la session.
     */
    #[Route('/api/token/refresh', name: 'api_token_refresh', methods: ['POST'])]
    public function refresh(Request $requete): JsonResponse
    {
        $this->csrf->verifier($requete);
        $jeton = $this->cookies->lireRefresh($requete) ?? throw new SessionInvalideException();

        $session = $this->sessions->rafraichir($jeton);
        $reponse = self::json(SessionResponse::depuis($session));
        $this->cookies->poser($reponse, $session->jetonRefresh);

        return $reponse;
    }

    #[Route('/api/deconnexion', name: 'api_deconnexion', methods: ['POST'])]
    public function deconnexion(#[CurrentUser] Utilisateur $utilisateur): Response
    {
        if (null === $utilisateur->jti || null === $utilisateur->familleId || null === $utilisateur->expiration) {
            throw new SessionInvalideException();
        }
        $this->sessions->deconnecter($utilisateur->jti, $utilisateur->expiration, $utilisateur->familleId);

        $reponse = new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
        $this->cookies->effacer($reponse);

        return $reponse;
    }

    /** @param array<string, mixed> $donnees */
    private static function json(array $donnees, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($donnees, $statut);
        $reponse->headers->set('Cache-Control', 'no-store');   // jetons et données personnelles : jamais en cache

        return $reponse;
    }
}
