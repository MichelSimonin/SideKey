<?php

declare(strict_types=1);

namespace App\Tests\Support\Commande;

use App\Exception\CommandeEnCoursException;
use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonAnnulableException;
use App\Exception\StockInsuffisantException;
use App\Service\Catalogue\Langue;
use App\Service\Commande\CommandeAnnulee;
use App\Service\Commande\CommandeCreee;
use App\Service\Commande\CommandeDetail;
use App\Service\Commande\Commandes;
use App\Service\Commande\LigneACommander;
use App\Service\Commande\LigneCommandeDetail;
use App\Service\Commande\OffreCommandable;

/** Doublure de CommandeRepository : une commande en attente par client, stock par offre, tout ou rien. */
final class CommandesEnMemoire implements Commandes
{
    /** @var array<string, OffreCommandable> */
    public array $offres = [];
    /** @var array<string, int> clés disponibles par offre */
    public array $stock = [];
    /** @var array<string, int> prix en centimes par offre */
    public array $prix = [];
    /** @var array<string, array{id: string, utilisateur: string, statut: string, lignes: list<LigneACommander>, langue: Langue, majeur: bool, expire: \DateTimeImmutable}> */
    public array $commandes = [];

    public function ajouterOffre(string $id, int $prixCentimes, int $stock, int $pegi = 12, bool $active = true): void
    {
        $this->offres[$id] = new OffreCommandable($id, 'jeu-'.$id, $pegi, $active);
        $this->stock[$id] = $stock;
        $this->prix[$id] = $prixCentimes;
    }

    public function offres(array $offreIds): array
    {
        return array_intersect_key($this->offres, array_flip($offreIds));
    }

    public function creer(string $utilisateurId, Langue $langue, bool $confirmeMajeur, array $lignes, int $dureeReservationSecondes): CommandeCreee
    {
        foreach ($this->commandes as $reference => $c) {
            if ($c['utilisateur'] === $utilisateurId && 'EN_ATTENTE_PAIEMENT' === $c['statut']) {
                throw new CommandeEnCoursException($reference);
            }
        }
        foreach ($lignes as $ligne) {
            if ($this->stock[$ligne->offreId] < $ligne->quantite) {
                throw new StockInsuffisantException($this->stock[$ligne->offreId]);   // rien n'est réservé
            }
        }
        foreach ($lignes as $ligne) {
            $this->stock[$ligne->offreId] -= $ligne->quantite;
        }
        $n = \count($this->commandes) + 1;
        $reference = sprintf('00000000-0000-4000-8000-%012d', $n);
        $expire = new \DateTimeImmutable('+'.$dureeReservationSecondes.' seconds');
        $this->commandes[$reference] = ['id' => 'id-'.$n, 'utilisateur' => $utilisateurId, 'statut' => 'EN_ATTENTE_PAIEMENT', 'lignes' => $lignes, 'langue' => $langue, 'majeur' => $confirmeMajeur, 'expire' => $expire];

        return new CommandeCreee('id-'.$n, $reference, $expire, array_values(array_unique(array_map(fn (LigneACommander $l): string => $this->offres[$l->offreId]->slug, $lignes))));
    }

    public function detail(string $reference, string $utilisateurId): ?CommandeDetail
    {
        $c = $this->commandes[$reference] ?? null;
        if (null === $c || $c['utilisateur'] !== $utilisateurId) {
            return null;
        }
        $lignes = array_map(fn (LigneACommander $l): LigneCommandeDetail => new LigneCommandeDetail($l->offreId, 'Jeu '.$l->offreId, 'STEAM', $l->quantite, $this->prix[$l->offreId], []), $c['lignes']);
        $total = array_sum(array_map(static fn (LigneCommandeDetail $l): int => $l->sousTotalCentimes(), $lignes));

        return new CommandeDetail($c['id'], $reference, $c['statut'], $total, new \DateTimeImmutable(), $c['expire'], null, $lignes);
    }

    public function lister(string $utilisateurId, int $limite): array
    {
        return [];
    }

    public function annuler(string $reference, string $utilisateurId): CommandeAnnulee
    {
        $c = $this->commandes[$reference] ?? null;
        if (null === $c || $c['utilisateur'] !== $utilisateurId) {
            throw new CommandeIntrouvableException();
        }
        if ('EN_ATTENTE_PAIEMENT' !== $c['statut']) {
            throw new CommandeNonAnnulableException();
        }
        $this->commandes[$reference]['statut'] = 'ANNULEE';
        $liberees = 0;
        foreach ($c['lignes'] as $ligne) {
            $this->stock[$ligne->offreId] += $ligne->quantite;
            $liberees += $ligne->quantite;
        }

        return new CommandeAnnulee($c['id'], $liberees, array_values(array_unique(array_map(fn (LigneACommander $l): string => $this->offres[$l->offreId]->slug, $c['lignes']))));
    }
}
