<?php

declare(strict_types=1);

namespace App\Tests\Support\Controller;

use App\Security\Permission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Sonde RBAC, chargée en environnement de test UNIQUEMENT (config/routes.yaml, when@test).
 *
 * Les routes du back-office n'existent pas encore (F-08 à F-10) ; cette sonde expose une route par permission
 * de la matrice du CDC §11.5 pour prouver, de bout en bout par HTTP (pare-feu, voter, gestionnaire d'erreurs),
 * que « accès refusé = 403 ». Elle suit exactement le chemin d'une vraie route : le contrôle se fait par
 * le même voter que #[IsGranted] utilisera.
 */
final class SondeRbacController
{
    public function __construct(private readonly AuthorizationCheckerInterface $autorisations)
    {
    }

    #[Route('/api/_sonde/{permission}', name: 'test_sonde_rbac', requirements: ['permission' => '[a-z_]+'], methods: ['GET'])]
    public function __invoke(string $permission): JsonResponse
    {
        $cible = null;
        foreach (Permission::cases() as $cas) {
            if ($cas->nom() === $permission) {
                $cible = $cas;
            }
        }
        if (null === $cible) {
            throw new AccessDeniedException();   // permission inconnue : refus par défaut
        }
        if (!$this->autorisations->isGranted($cible->value)) {
            throw new AccessDeniedException();
        }

        return new JsonResponse(['accorde' => $permission]);
    }
}
