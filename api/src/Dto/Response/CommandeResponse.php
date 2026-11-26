<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Service\Commande\CleCommande;
use App\Service\Commande\CommandeDetail;
use App\Service\Commande\CommandeResume;
use App\Service\Commande\LigneCommandeDetail;

/**
 * Contrat de sortie des commandes. La référence est le seul identifiant exposé ; les montants sont des
 * chaînes décimales calculées côté serveur ; une clé n'apparaît que par son identifiant (révélation : F-08).
 */
final class CommandeResponse
{
    /** @return array<string, mixed> */
    public static function detail(CommandeDetail $commande): array
    {
        return [
            'reference' => $commande->reference,
            'statut' => $commande->statut,
            'total' => PanierResponse::montant($commande->montantCentimes),
            'creeeLe' => $commande->creeLe->format(\DateTimeInterface::ATOM),
            'expireLe' => $commande->enAttenteDePaiement() ? $commande->expireLe->format(\DateTimeInterface::ATOM) : null,
            'secondesRestantes' => $commande->secondesRestantes,
            'payeeLe' => $commande->payeeLe?->format(\DateTimeInterface::ATOM),
            'lignes' => array_map(static fn (LigneCommandeDetail $l): array => [
                'offreId' => $l->offreId,
                'titre' => $l->titre,
                'plateforme' => $l->plateforme,
                'quantite' => $l->quantite,
                'prixUnitaire' => PanierResponse::montant($l->prixUnitaireCentimes),
                'sousTotal' => PanierResponse::montant($l->sousTotalCentimes()),
                'cles' => array_map(static fn (CleCommande $c): array => [
                    'id' => $c->id,
                    'revelee' => null !== $c->reveleeLe,
                    'reveleeLe' => $c->reveleeLe?->format(\DateTimeInterface::ATOM),
                ], $l->cles),
            ], $commande->lignes),
        ];
    }

    /**
     * @param list<CommandeResume> $commandes
     *
     * @return array{commandes: list<array{reference: string, statut: string, total: string, articles: int, creeeLe: string}>}
     */
    public static function liste(array $commandes): array
    {
        return ['commandes' => array_map(static fn (CommandeResume $c): array => [
            'reference' => $c->reference,
            'statut' => $c->statut,
            'total' => PanierResponse::montant($c->montantCentimes),
            'articles' => $c->nombreArticles,
            'creeeLe' => $c->creeLe->format(\DateTimeInterface::ATOM),
        ], $commandes)];
    }
}
