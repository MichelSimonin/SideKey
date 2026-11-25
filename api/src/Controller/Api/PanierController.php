<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\AjouterLignePanierRequest;
use App\Dto\Request\ModifierLignePanierRequest;
use App\Dto\Response\PanierResponse;
use App\Service\Panier\Panier;
use App\Service\Panier\PanierCookie;
use App\Service\Panier\PanierService;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Panier (US-10, US-11, US-13). Couche présentation : validation des entrées par DTO,
 * aucune règle métier ici, tout passe par PanierService.
 */
#[Route('/api/panier', name: 'api_panier_')]
final class PanierController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly PanierService $paniers,
        private readonly PanierCookie $cookies,
    ) {
    }

    #[Route('', name: 'consulter', methods: ['GET'])]
    public function consulter(Request $requete): JsonResponse
    {
        $panierId = $this->cookies->lire($requete);
        $panier = null === $panierId ? new Panier([]) : $this->paniers->consulter($panierId);

        return $this->reponse($panier);
    }

    #[Route('/lignes', name: 'ajouter', methods: ['POST'])]
    public function ajouter(Request $requete, #[MapRequestPayload] AjouterLignePanierRequest $corps): JsonResponse
    {
        [$panierId, $cookie] = $this->cookies->lireOuCreer($requete);

        return $this->reponse($this->paniers->ajouter($panierId, strtolower($corps->offreId), $corps->quantite), $cookie);
    }

    #[Route('/lignes/{offreId}', name: 'modifier', requirements: ['offreId' => self::UUID], methods: ['PATCH'])]
    public function modifier(Request $requete, string $offreId, #[MapRequestPayload] ModifierLignePanierRequest $corps): JsonResponse
    {
        [$panierId, $cookie] = $this->cookies->lireOuCreer($requete);

        return $this->reponse($this->paniers->modifier($panierId, strtolower($offreId), (int) $corps->quantite), $cookie);
    }

    #[Route('/lignes/{offreId}', name: 'retirer', requirements: ['offreId' => self::UUID], methods: ['DELETE'])]
    public function retirer(Request $requete, string $offreId): JsonResponse
    {
        $panierId = $this->cookies->lire($requete);
        $panier = null === $panierId ? new Panier([]) : $this->paniers->retirer($panierId, strtolower($offreId));

        return $this->reponse($panier);
    }

    #[Route('', name: 'vider', methods: ['DELETE'])]
    public function vider(Request $requete): Response
    {
        $panierId = $this->cookies->lire($requete);
        if (null !== $panierId) {
            $this->paniers->vider($panierId);
        }

        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }

    private function reponse(Panier $panier, ?Cookie $cookie = null): JsonResponse
    {
        $reponse = new JsonResponse(PanierResponse::depuis($panier));
        $reponse->headers->set('Cache-Control', 'no-store');   // contenu propre au visiteur
        if (null !== $cookie) {
            $reponse->headers->setCookie($cookie);
        }

        return $reponse;
    }
}
