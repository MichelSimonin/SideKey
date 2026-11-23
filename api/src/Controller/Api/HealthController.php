<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sonde de disponibilité utilisée par la CI, le déploiement et la supervision.
 * Ne renvoie aucune information sensible (ni version, ni environnement).
 */
final class HealthController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $response = new JsonResponse(['status' => 'ok'], Response::HTTP_OK);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
