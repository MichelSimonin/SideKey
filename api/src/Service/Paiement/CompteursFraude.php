<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Compteurs anti-fraude du client (CDC §9.8). Implémentation : Infrastructure/Redis/RedisCompteursFraude.
 *
 * Redis n'est qu'un compteur de comportement, jamais une donnée de référence : sa perte remet les compteurs à zéro mais
 * ne perd ni commande ni clé. Pour cette raison même, une panne de Redis REFUSE le paiement plutôt que de le laisser
 * passer sans contrôle (Fail Securely) : toute méthode laisse remonter \RedisException et PaiementService la convertit.
 */
interface CompteursFraude
{
    /** Secondes de blocage restantes, ou null si le client n'est pas bloqué. */
    public function secondesDeBlocage(string $utilisateurId): ?int;

    /** Clés payées par ce client sur les 24 dernières heures (fenêtre glissante, pas une journée civile). */
    public function clesAcheteesSur24h(string $utilisateurId): int;

    /** Enregistre les clés d'une commande payée ; rejouer la même commande ne compte pas deux fois. */
    public function enregistrerClesAchetees(string $utilisateurId, string $commandeId, int $nombre): void;

    /**
     * Compte un échec de paiement ; au seuil, pose le blocage.
     *
     * @return int tentatives restantes avant blocage (0 : le client vient d'être bloqué)
     */
    public function enregistrerEchec(string $utilisateurId): int;

    /** Un paiement accepté remet le compteur d'échecs à zéro. */
    public function reinitialiserEchecs(string $utilisateurId): void;
}
