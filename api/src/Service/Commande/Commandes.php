<?php

declare(strict_types=1);

namespace App\Service\Commande;

use App\Exception\CommandeEnCoursException;
use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonAnnulableException;
use App\Exception\OffreIndisponibleException;
use App\Exception\StockInsuffisantException;
use App\Service\Catalogue\Langue;

/**
 * Accès aux commandes (couche d'accès aux données). Implémentation : Repository/CommandeRepository
 * (DBAL, SQL paramétré, compte sk_app, fonctions PL/pgSQL fn_reserver_cles et fn_annuler_commande).
 *
 * Toute lecture est filtrée sur le client : une commande d'autrui n'existe pas pour lui (anti-IDOR).
 */
interface Commandes
{
    /**
     * @param list<string> $offreIds
     *
     * @return array<string, OffreCommandable> indexé par offre ; une offre inconnue est absente
     */
    public function offres(array $offreIds): array;

    /**
     * Crée la commande, ses lignes au prix du jour et réserve les clés, dans UNE transaction
     * (READ COMMITTED et verrous de ligne, CDC §10.3). Tout ou rien : un stock insuffisant annule tout.
     *
     * @param list<LigneACommander> $lignes
     *
     * @throws CommandeEnCoursException   le client a déjà une commande en attente de paiement
     * @throws OffreIndisponibleException une offre a été retirée de la vente entre-temps
     * @throws StockInsuffisantException  une offre n'a plus assez de clés (SK001)
     */
    public function creer(string $utilisateurId, Langue $langue, bool $confirmeMajeur, array $lignes, int $dureeReservationSecondes): CommandeCreee;

    public function detail(string $reference, string $utilisateurId): ?CommandeDetail;

    /** @return list<CommandeResume> les plus récentes d'abord */
    public function lister(string $utilisateurId, int $limite): array;

    /**
     * @throws CommandeIntrouvableException  inexistante ou d'un autre client
     * @throws CommandeNonAnnulableException plus en attente de paiement (SK005)
     */
    public function annuler(string $reference, string $utilisateurId): CommandeAnnulee;
}
