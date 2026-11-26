<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\PayerCommandeRequest;
use App\Dto\Response\PaiementResponse;
use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Paiement\PaiementService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paiement simulé d'une commande (US-32, US-33, US-36). Couche présentation.
 *
 * Contrôles de cette couche : permission « commander » (CLIENT), référence au format UUID, corps JSON uniquement et validé,
 * en-tête Idempotency-Key transmis tel quel (son format est contrôlé par le service). Le montant n'est jamais lu de la
 * requête ; la propriété de la commande, l'idempotence et l'anti-fraude relèvent du service et des requêtes SQL.
 * La réponse est « no-store » : données personnelles, et un rejeu doit toujours être demandé au serveur.
 */
#[Route('/api/commandes', name: 'api_paiement_')]
#[IsGranted(Permission::Commander->value)]
final class PaiementController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(private readonly PaiementService $paiements)
    {
    }

    #[Route('/{reference}/paiement', name: 'payer', requirements: ['reference' => self::UUID], methods: ['POST'])]
    public function payer(
        Request $requete,
        string $reference,
        #[CurrentUser] Utilisateur $utilisateur,
        #[MapRequestPayload(acceptFormat: 'json')] PayerCommandeRequest $corps,
    ): JsonResponse {
        $paiement = $this->paiements->payer(
            $utilisateur->compte,
            $reference,
            $corps->numeroCarte,
            $corps->expiration,
            $corps->cryptogramme,
            $requete->headers->get('Idempotency-Key'),
            $requete->getClientIp(),
        );

        $reponse = new JsonResponse(PaiementResponse::depuis($paiement));
        $reponse->headers->set('Cache-Control', 'no-store');
        if ($paiement->rejeu) {
            $reponse->headers->set('Idempotent-Replayed', 'true');
        }

        return $reponse;
    }
}
