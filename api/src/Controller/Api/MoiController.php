<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Response\UtilisateurResponse;
use App\Security\Utilisateur;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Profil de l'utilisateur connecté. Route protégée par le pare-feu (access_control) : 401 sans jeton valide. */
final class MoiController
{
    #[Route('/api/moi', name: 'api_moi', methods: ['GET'])]
    public function __invoke(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $reponse = new JsonResponse(['utilisateur' => UtilisateurResponse::depuis($utilisateur->compte)]);
        $reponse->headers->set('Cache-Control', 'no-store');   // données personnelles

        return $reponse;
    }
}
