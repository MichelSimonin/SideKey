<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Request\JeuRequest;
use App\Dto\Request\RechercheJeuxAdminRequest;
use App\Dto\Response\CatalogueAdminResponse;
use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Catalogue\Gestion\GestionJeux;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office : jeux (US-50, écran E12). DELETE est une suppression logique (jeu désactivé) : les offres, les clés et
 * l'historique des commandes sont conservés.
 */
#[Route('/api/admin', name: 'api_admin_jeux_')]
#[IsGranted(Permission::GererCatalogue->value)]
final class JeuxAdminController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private readonly GestionJeux $jeux)
    {
    }

    #[Route('/genres', name: 'genres', methods: ['GET'])]
    public function genres(): JsonResponse
    {
        return ReponseAdmin::json(['genres' => $this->jeux->genres()]);
    }

    #[Route('/jeux', name: 'liste', methods: ['GET'])]
    public function liste(#[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] RechercheJeuxAdminRequest $criteres = new RechercheJeuxAdminRequest()): JsonResponse
    {
        return ReponseAdmin::json(CatalogueAdminResponse::pageJeux($this->jeux->lister($criteres->q, $criteres->actif(), $criteres->page)));
    }

    #[Route('/jeux/{id}', name: 'fiche', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function fiche(string $id): JsonResponse
    {
        return ReponseAdmin::json(CatalogueAdminResponse::jeu($this->jeux->trouver($id)));
    }

    #[Route('/jeux', name: 'creer', methods: ['POST'])]
    public function creer(Request $requete, #[MapRequestPayload(acceptFormat: 'json')] JeuRequest $corps, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $jeu = $this->jeux->creer($corps->versSaisie(), $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::json(CatalogueAdminResponse::jeu($jeu), Response::HTTP_CREATED);
    }

    #[Route('/jeux/{id}', name: 'modifier', requirements: ['id' => self::UUID], methods: ['PUT'])]
    public function modifier(Request $requete, string $id, #[MapRequestPayload(acceptFormat: 'json')] JeuRequest $corps, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $jeu = $this->jeux->modifier($id, $corps->versSaisie(), $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::json(CatalogueAdminResponse::jeu($jeu));
    }

    #[Route('/jeux/{id}', name: 'desactiver', requirements: ['id' => self::UUID], methods: ['DELETE'])]
    public function desactiver(Request $requete, string $id, #[CurrentUser] Utilisateur $utilisateur): Response
    {
        $this->jeux->desactiver($id, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::vide();
    }
}
