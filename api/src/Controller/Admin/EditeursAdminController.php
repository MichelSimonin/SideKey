<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Request\EditeurRequest;
use App\Dto\Response\CatalogueAdminResponse;
use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Catalogue\Gestion\GestionEditeurs;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office : éditeurs (US-50). Couche présentation : permission « gérer le catalogue » (GESTIONNAIRE, ADMIN) avec A2F
 * (jeton « a2f »), validation du corps par DTO, aucune règle ici.
 */
#[Route('/api/admin/editeurs', name: 'api_admin_editeurs_')]
#[IsGranted(Permission::GererCatalogue->value)]
final class EditeursAdminController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private readonly GestionEditeurs $editeurs)
    {
    }

    #[Route('', name: 'liste', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        return ReponseAdmin::json(['editeurs' => array_map(CatalogueAdminResponse::editeur(...), $this->editeurs->lister())]);
    }

    #[Route('', name: 'creer', methods: ['POST'])]
    public function creer(Request $requete, #[MapRequestPayload(acceptFormat: 'json')] EditeurRequest $corps, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $editeur = $this->editeurs->creer($corps->nom, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::json(CatalogueAdminResponse::editeur($editeur), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'modifier', requirements: ['id' => self::UUID], methods: ['PUT'])]
    public function modifier(Request $requete, string $id, #[MapRequestPayload(acceptFormat: 'json')] EditeurRequest $corps, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $editeur = $this->editeurs->modifier($id, $corps->nom, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::json(CatalogueAdminResponse::editeur($editeur));
    }

    #[Route('/{id}', name: 'supprimer', requirements: ['id' => self::UUID], methods: ['DELETE'])]
    public function supprimer(Request $requete, string $id, #[CurrentUser] Utilisateur $utilisateur): Response
    {
        $this->editeurs->supprimer($id, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::vide();
    }
}
