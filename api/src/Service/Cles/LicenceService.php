<?php

declare(strict_types=1);

namespace App\Service\Cles;

use App\Exception\CleIntrouvableException;
use App\Exception\CleNonRevelableException;
use App\Security\Voter\CleLicenceVoter;
use App\Service\Audit\JournalAudit;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Révélation d'une clé de licence à son acheteur (US-34, BX-02, BX-03, BX-16). Couche métier.
 *
 * C'est le SEUL endroit qui déchiffre une clé (CDC §9.4, ADR-0004). Trois verrous successifs :
 *   1. la requête SQL ne lit que les clés des commandes de l'utilisateur (une clé d'autrui est introuvable : 404) ;
 *   2. CleLicenceVoter vérifie propriétaire, commande PAYEE et clé VENDUE (sinon 409 pour son propriétaire) ;
 *   3. le chiffré est authentifié par GCM et lié à son offre : un chiffré altéré ou déplacé est refusé.
 * Chaque affichage est journalisé, la première révélation est datée (RG-20 : plus de remboursement).
 */
final class LicenceService
{
    public function __construct(
        private readonly ClesLicence $cles,
        private readonly ChiffrementCles $chiffrement,
        private readonly AuthorizationCheckerInterface $autorisations,
        private readonly JournalAudit $journal,
    ) {
    }

    public function reveler(string $cleId, string $utilisateurId, ?string $adresseIp): CleRevelee
    {
        $cle = $this->cles->cleDuProprietaire($cleId, $utilisateurId);
        if (null === $cle) {
            throw new CleIntrouvableException();
        }
        if (!$this->autorisations->isGranted(CleLicenceVoter::REVELER, $cle)) {
            throw new CleNonRevelableException();   // commande pas (ou plus) payée, ou en attente de vérification
        }

        $valeur = $this->chiffrement->dechiffrer($cle->chiffre, $cle->offreId);
        $reveleeLe = $this->cles->marquerRevelee($cle->id);
        $this->journal->tracer(JournalAudit::REVELATION_CLE, 'cle_licence', $cle->id, $utilisateurId, $adresseIp);

        return new CleRevelee($cle->id, $valeur, $reveleeLe);
    }
}
