<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Request\OffreCreationRequest;
use App\Dto\Request\OffreModificationRequest;
use App\Dto\Response\CatalogueAdminResponse;
use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Catalogue\Gestion\GestionOffres;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office : offres jeu × plateforme (US-51). Pas de suppression : une offre se désactive (ses clés et ses commandes
 * restent). La modification exige la version lue : 409 si un collègue est passé avant.
 */
#[Route('/api/admin', name: 'api_admin_offres_')]
#[IsGranted(Permission::GererCatalogue->value)]
final class OffresAdminController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private readonly GestionOffres $offres)
    {
    }

    #[Route('/jeux/{id}/offres', name: 'creer', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function creer(Request $requete, string $id, #[MapRequestPayload(acceptFormat: 'json')] OffreCreationRequest $corps, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $offre = $this->offres->creer($id, $corps->plateformeId, $corps->prix, (int) $corps->seuilAlerte, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::json(CatalogueAdminResponse::offre($offre), Response::HTTP_CREATED);
    }

    #[Route('/offres/{offreId}', name: 'modifier', requirements: ['offreId' => self::UUID], methods: ['PUT'])]
    public function modifier(Request $requete, string $offreId, #[MapRequestPayload(acceptFormat: 'json')] OffreModificationRequest $corps, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $offre = $this->offres->modifier(
            $offreId, $corps->prix, (int) $corps->seuilAlerte, (bool) $corps->actif, (int) $corps->version,
            $utilisateur->compte->id, $requete->getClientIp(),
        );

        return ReponseAdmin::json(CatalogueAdminResponse::offre($offre));
    }
}
