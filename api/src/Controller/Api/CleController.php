<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Cles\LicenceService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Révélation d'une clé achetée (US-34). POST : l'appel a un effet (journal d'audit, date de révélation).
 * Couche présentation : permission « commander » (CLIENT), identifiant au format UUID ; la propriété et
 * le statut de la commande sont vérifiés par LicenceService et CleLicenceVoter.
 */
final class CleController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private readonly LicenceService $licences)
    {
    }

    #[Route('/api/cles/{id}/revelation', name: 'api_cle_revelation', requirements: ['id' => self::UUID], methods: ['POST'])]
    #[IsGranted(Permission::Commander->value)]
    public function reveler(Request $requete, string $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $cle = $this->licences->reveler(strtolower($id), $utilisateur->compte->id, $requete->getClientIp());

        $reponse = new JsonResponse([
            'id' => $cle->id,
            'cle' => $cle->cle,
            'reveleeLe' => $cle->reveleeLe->format(\DateTimeInterface::ATOM),
        ]);
        // La clé ne doit rester dans aucun cache, ni navigateur ni intermédiaire
        $reponse->headers->set('Cache-Control', 'no-store');
        $reponse->headers->set('Pragma', 'no-cache');

        return $reponse;
    }
}
