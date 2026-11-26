<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Réponses du back-office : jamais mises en cache (données de gestion, y compris par un proxy ou un navigateur partagé). */
final class ReponseAdmin
{
    /** @param array<array-key, mixed> $donnees */
    public static function json(array $donnees, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($donnees, $statut);
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }

    public static function vide(): Response
    {
        return new Response(null, Response::HTTP_NO_CONTENT, ['Cache-Control' => 'no-store']);
    }
}
