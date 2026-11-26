<?php

declare(strict_types=1);

namespace App\Service\Paiement;

use App\Exception\CommandeNonPayableException;
use App\Exception\PaiementEnCoursException;

/**
 * Accès aux paiements (couche d'accès aux données). Implémentation : Repository/PaiementRepository (DBAL, SQL paramétré,
 * compte sk_app, fn_valider_commande). Toute lecture est filtrée sur le client : la commande d'autrui n'existe pas pour lui.
 */
interface Paiements
{
    /** Tentative déjà enregistrée sous cette clé (rejeu), ou null. */
    public function parCle(string $empreinte, string $utilisateurId): ?PaiementExistant;

    /** Commande à payer et éléments de décision anti-fraude, ou null si elle n'existe pas pour ce client. */
    public function contexte(string $reference, string $utilisateurId): ?ContextePaiement;

    /**
     * Ouvre une tentative EN_ATTENTE pour la commande, avant d'appeler le prestataire. Verrouille la commande, revérifie qu'elle
     * attend son paiement et qu'elle ne va pas expirer ($margeSecondes), puis insère. L'index unique des paiements actifs
     * garantit qu'un seul paiement par commande atteint le prestataire, même hors transaction.
     *
     * @throws CommandeNonPayableException payée, à vérifier, expirée ou annulée
     * @throws PaiementEnCoursException    un autre paiement de cette commande est en cours
     */
    public function ouvrir(string $reference, string $utilisateurId, string $empreinte, int $margeSecondes): PaiementOuvert;

    /**
     * Paiement accepté : enregistre le résultat ET fait avancer la commande, dans UNE transaction. Suspecte : « à vérifier »
     * (clés conservées, non révélables) ; sinon PAYEE et clés VENDUE (fn_valider_commande).
     *
     * @return string statut de la commande : PAYEE ou A_VERIFIER
     *
     * @throws CommandeNonPayableException la réservation a expiré pendant l'appel au prestataire (rien n'est modifié)
     */
    public function accepter(string $paiementId, string $codeRetour, string $derniersChiffres, bool $suspecte, string $utilisateurId): string;

    /** Enregistre un refus, une erreur du prestataire ou un paiement impossible à valider ; la commande ne change pas. */
    public function terminer(string $paiementId, string $statut, string $codeRetour, ?string $derniersChiffres): void;
}
