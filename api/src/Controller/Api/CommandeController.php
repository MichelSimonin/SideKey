<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\Request\PasserCommandeRequest;
use App\Dto\Response\CommandeResponse;
use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Catalogue\Langue;
use App\Service\Commande\CommandeService;
use App\Service\Panier\PanierCookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Commandes du client (US-30, US-31, US-33, US-35). Couche présentation.
 *
 * Contrôles de cette couche : permission « commander » (CLIENT), référence au format UUID, corps validé.
 * Le compte vérifié, la majorité, le stock et la propriété de la commande sont vérifiés par CommandeService
 * et par les requêtes SQL (filtrées sur le client). Toutes les réponses sont « no-store ».
 */
#[Route('/api/commandes', name: 'api_commandes_')]
#[IsGranted(Permission::Commander->value)]
final class CommandeController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    public function __construct(
        private readonly CommandeService $commandes,
        private readonly PanierCookie $paniers,
    ) {
    }

    /** Valide le panier : commande EN_ATTENTE_PAIEMENT et clés réservées 15 min (RG-12). */
    #[Route('', name: 'passer', methods: ['POST'])]
    public function passer(Request $requete, #[CurrentUser] Utilisateur $utilisateur, #[MapRequestPayload] PasserCommandeRequest $corps = new PasserCommandeRequest()): JsonResponse
    {
        $commande = $this->commandes->passer($utilisateur->compte, $this->paniers->lire($requete), $corps->confirmeMajeur, Langue::depuis($requete->getLocale()));

        return $this->reponse(CommandeResponse::detail($commande), Response::HTTP_CREATED);
    }

    #[Route('', name: 'lister', methods: ['GET'])]
    public function lister(#[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        return $this->reponse(CommandeResponse::liste($this->commandes->lister($utilisateur->compte->id)));
    }

    #[Route('/{reference}', name: 'consulter', requirements: ['reference' => self::UUID], methods: ['GET'])]
    public function consulter(string $reference, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        return $this->reponse(CommandeResponse::detail($this->commandes->consulter(strtolower($reference), $utilisateur->compte->id)));
    }

    /** Annulation par le client tant que la commande attend son paiement : les clés reviennent en stock. */
    #[Route('/{reference}/annulation', name: 'annuler', requirements: ['reference' => self::UUID], methods: ['POST'])]
    public function annuler(string $reference, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        return $this->reponse(CommandeResponse::detail($this->commandes->annuler(strtolower($reference), $utilisateur->compte->id)));
    }

    /** @param array<string, mixed> $contenu */
    private function reponse(array $contenu, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($contenu, $statut);
        $reponse->headers->set('Cache-Control', 'no-store');   // données personnelles

        return $reponse;
    }
}
