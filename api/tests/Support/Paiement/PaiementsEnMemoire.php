<?php

declare(strict_types=1);

namespace App\Tests\Support\Paiement;

use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonPayableException;
use App\Exception\PaiementEnCoursException;
use App\Service\Commande\LigneCommandeDetail;
use App\Service\Paiement\ContextePaiement;
use App\Service\Paiement\PaiementExistant;
use App\Service\Paiement\PaiementOuvert;
use App\Service\Paiement\Paiements;
use App\Tests\Support\Commande\CommandesEnMemoire;
use Psr\Clock\ClockInterface;

/**
 * Doublure de PaiementRepository, adossée aux commandes en mémoire : mêmes règles que la base (un seul paiement actif par
 * commande, clé unique, accepter fait avancer la commande), sans PostgreSQL.
 */
final class PaiementsEnMemoire implements Paiements
{
    /** @var array<string, array{statut: string, code: ?string, derniers: ?string, cle: string, reference: string, utilisateur: string, montant: int}> */
    public array $paiements = [];
    public \DateTimeImmutable $compteCreeLe;
    public bool $premiereCommande = true;
    /** Simule une réservation qui expire PENDANT l'appel au prestataire : accepter() échoue. */
    public bool $expireDurantLAppel = false;
    /** @var list<string> */
    public array $journal = [];

    public function __construct(private readonly CommandesEnMemoire $commandes, private readonly ClockInterface $horloge)
    {
        $this->compteCreeLe = $horloge->now()->modify('-30 days');
    }

    public function parCle(string $empreinte, string $utilisateurId): ?PaiementExistant
    {
        foreach ($this->paiements as $id => $p) {
            if ($p['cle'] === $empreinte && $p['utilisateur'] === $utilisateurId) {
                return new PaiementExistant($id, $p['statut'], $p['code'], $p['derniers'], $p['reference']);
            }
        }

        return null;
    }

    public function contexte(string $reference, string $utilisateurId): ?ContextePaiement
    {
        $detail = $this->commandes->detail($reference, $utilisateurId);
        if (null === $detail) {
            return null;
        }

        return new ContextePaiement(
            $detail->id, $reference, $detail->statut, $detail->montantCentimes,
            array_sum(array_map(static fn (LigneCommandeDetail $l): int => $l->quantite, $detail->lignes)),
            $detail->expireLe, $this->compteCreeLe, $this->premiereCommande,
        );
    }

    public function ouvrir(string $reference, string $utilisateurId, string $empreinte, int $margeSecondes): PaiementOuvert
    {
        $detail = $this->commandes->detail($reference, $utilisateurId) ?? throw new CommandeIntrouvableException();
        if ('EN_ATTENTE_PAIEMENT' !== $detail->statut || $detail->expireLe <= $this->horloge->now()->modify('+'.$margeSecondes.' seconds')) {
            throw new CommandeNonPayableException();
        }
        foreach ($this->paiements as $p) {
            if ($p['reference'] === $reference && \in_array($p['statut'], ['EN_ATTENTE', 'ACCEPTE'], true)) {
                throw new PaiementEnCoursException();   // uq_paiement_commande_actif
            }
        }
        $id = sprintf('00000000-0000-4000-9000-%012d', \count($this->paiements) + 1);
        $this->paiements[$id] = ['statut' => 'EN_ATTENTE', 'code' => null, 'derniers' => null, 'cle' => $empreinte, 'reference' => $reference, 'utilisateur' => $utilisateurId, 'montant' => $detail->montantCentimes];
        $this->journal[] = 'ouvrir';

        return new PaiementOuvert($id, $detail->id);
    }

    public function accepter(string $paiementId, string $codeRetour, string $derniersChiffres, bool $suspecte, string $utilisateurId): string
    {
        $this->journal[] = 'accepter';
        if ($this->expireDurantLAppel) {
            throw new CommandeNonPayableException();   // transaction annulée : le paiement reste EN_ATTENTE
        }
        $this->paiements[$paiementId]['statut'] = 'ACCEPTE';
        $this->paiements[$paiementId]['code'] = $codeRetour;
        $this->paiements[$paiementId]['derniers'] = $derniersChiffres;
        $statut = $suspecte ? 'A_VERIFIER' : 'PAYEE';
        $this->commandes->commandes[$this->paiements[$paiementId]['reference']]['statut'] = $statut;

        return $statut;
    }

    public function terminer(string $paiementId, string $statut, string $codeRetour, ?string $derniersChiffres): void
    {
        $this->journal[] = 'terminer:'.$statut;
        $this->paiements[$paiementId]['statut'] = $statut;
        $this->paiements[$paiementId]['code'] = $codeRetour;
        $this->paiements[$paiementId]['derniers'] = $derniersChiffres;
    }

    /** @return list<string> statuts des paiements enregistrés */
    public function statuts(): array
    {
        return array_values(array_map(static fn (array $p): string => $p['statut'], $this->paiements));
    }
}
