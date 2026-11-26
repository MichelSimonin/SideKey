<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Request\PlateformeCreationRequest;
use App\Dto\Request\PlateformeModificationRequest;
use App\Dto\Response\CatalogueAdminResponse;
use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Catalogue\Gestion\GestionPlateformes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Back-office : plateformes (US-50). Code immuable, libellé modifiable ; suppression refusée si une offre l'utilise (409). */
#[Route('/api/admin/plateformes', name: 'api_admin_plateformes_')]
#[IsGranted(Permission::GererCatalogue->value)]
final class PlateformesAdminController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private readonly GestionPlateformes $plateformes)
    {
    }

    #[Route('', name: 'liste', methods: ['GET'])]
    public function liste(): JsonResponse
    {
        return ReponseAdmin::json(['plateformes' => array_map(CatalogueAdminResponse::plateforme(...), $this->plateformes->lister())]);
    }

    #[Route('', name: 'creer', methods: ['POST'])]
    public function creer(Request $requete, #[MapRequestPayload(acceptFormat: 'json')] PlateformeCreationRequest $corps, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $plateforme = $this->plateformes->creer($corps->code, $corps->libelle, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::json(CatalogueAdminResponse::plateforme($plateforme), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'modifier', requirements: ['id' => self::UUID], methods: ['PUT'])]
    public function modifier(Request $requete, string $id, #[MapRequestPayload(acceptFormat: 'json')] PlateformeModificationRequest $corps, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $plateforme = $this->plateformes->modifier($id, $corps->libelle, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::json(CatalogueAdminResponse::plateforme($plateforme));
    }

    #[Route('/{id}', name: 'supprimer', requirements: ['id' => self::UUID], methods: ['DELETE'])]
    public function supprimer(Request $requete, string $id, #[CurrentUser] Utilisateur $utilisateur): Response
    {
        $this->plateformes->supprimer($id, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::vide();
    }
}
