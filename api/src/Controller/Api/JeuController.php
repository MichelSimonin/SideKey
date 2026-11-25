<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\RechercheCatalogueRequest;
use App\Dto\Response\CatalogueResponse;
use App\Service\Catalogue\CatalogueService;
use App\Service\Catalogue\Langue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Catalogue public (US-01 à US-05) : liste filtrée, fiche, valeurs des filtres. Couche présentation : validation
 * du DTO, langue de la requête, en-têtes de cache ; aucune requête, aucune règle ici, tout passe par CatalogueService.
 * Réponses publiques : pas d'authentification, pas de donnée personnelle, mise en cache HTTP courte.
 */
final class JeuController
{
    public function __construct(private readonly CatalogueService $catalogue)
    {
    }

    #[Route('/api/jeux', name: 'api_jeux_liste', methods: ['GET'])]
    public function liste(Request $requete, #[MapQueryString(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)] RechercheCatalogueRequest $criteres = new RechercheCatalogueRequest()): JsonResponse
    {
        $page = $this->catalogue->liste($criteres->versFiltres(), Langue::depuis($requete->getLocale()));

        return $this->reponse(CatalogueResponse::liste($page), 60);
    }

    /** Déclarée avant {slug} : « filtres » n'est pas un slug de jeu. */
    #[Route('/api/catalogue/filtres', name: 'api_catalogue_filtres', methods: ['GET'])]
    public function filtres(Request $requete): JsonResponse
    {
        return $this->reponse(CatalogueResponse::filtres($this->catalogue->valeursFiltres(Langue::depuis($requete->getLocale()))), 300);
    }

    #[Route('/api/jeux/{slug}', name: 'api_jeux_fiche', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function fiche(Request $requete, string $slug): JsonResponse
    {
        return $this->reponse(CatalogueResponse::fiche($this->catalogue->fiche($slug, Langue::depuis($requete->getLocale()))), 60);
    }

    /** @param array<string, mixed> $donnees */
    private function reponse(array $donnees, int $maxAge): JsonResponse
    {
        $reponse = new JsonResponse($donnees);
        $reponse->setPublic();
        $reponse->setMaxAge($maxAge);                          // le catalogue est le même pour tous
        $reponse->setVary(['Accept-Language']);                // sauf la langue

        return $reponse;
    }
}
