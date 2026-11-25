<?php

declare(strict_types=1);

namespace App\Service\Panier;

use App\Exception\OffreIndisponibleException;
use App\Exception\PanierPleinException;
use App\Exception\QuantiteMaximaleException;
use App\Exception\StockInsuffisantException;

/**
 * Règles du panier (US-10, US-11, US-13) :
 *  - quantité par offre bornée par RG-14 (5 clés par offre et par commande) et par le stock disponible ;
 *  - 20 offres différentes au plus (limite défensive contre les paniers géants) ;
 *  - prix, titres et stock relus en base à chaque affichage : total calculé côté serveur (RG-11) ;
 *  - une offre supprimée, désactivée ou en rupture est retirée, une quantité au-delà du stock est
 *    ramenée au stock, et le client en est informé.
 */
final class PanierService
{
    public const QUANTITE_MAX_PAR_OFFRE = 5;
    public const OFFRES_MAX = 20;

    public function __construct(
        private readonly PanierStore $store,
        private readonly CatalogueOffres $catalogue,
    ) {
    }

    public function consulter(PanierId $panierId): Panier
    {
        $contenu = $this->store->lire($panierId);
        if ([] === $contenu) {
            return new Panier([]);
        }

        $offres = $this->catalogue->disponibilites(array_keys($contenu));
        $lignes = [];
        $retirees = [];
        foreach ($contenu as $offreId => $quantite) {
            $offre = $offres[$offreId] ?? null;
            $maximum = null === $offre ? 0 : $this->quantiteMax($offre);
            if (null === $offre || !$offre->active || 0 === $maximum) {
                $this->store->retirer($panierId, $offreId);
                $retirees[] = $offreId;
                continue;
            }
            $ajustee = $quantite > $maximum;
            if ($ajustee) {
                $quantite = $maximum;
                $this->store->definir($panierId, $offreId, $quantite);
            }
            $lignes[] = new LignePanier($offre, $quantite, $maximum, $ajustee);
        }

        return new Panier($lignes, $retirees);
    }

    /** Ajoute une quantité à la ligne de l'offre (la crée si besoin). */
    public function ajouter(PanierId $panierId, string $offreId, int $quantite): Panier
    {
        $contenu = $this->store->lire($panierId);
        if (!isset($contenu[$offreId]) && \count($contenu) >= self::OFFRES_MAX) {
            throw new PanierPleinException(self::OFFRES_MAX);
        }

        return $this->fixer($panierId, $offreId, ($contenu[$offreId] ?? 0) + $quantite);
    }

    /** Fixe la quantité d'une ligne ; 0 retire la ligne. */
    public function modifier(PanierId $panierId, string $offreId, int $quantite): Panier
    {
        if (0 === $quantite) {
            return $this->retirer($panierId, $offreId);
        }
        $contenu = $this->store->lire($panierId);
        if (!isset($contenu[$offreId]) && \count($contenu) >= self::OFFRES_MAX) {
            throw new PanierPleinException(self::OFFRES_MAX);
        }

        return $this->fixer($panierId, $offreId, $quantite);
    }

    public function retirer(PanierId $panierId, string $offreId): Panier
    {
        $this->store->retirer($panierId, $offreId);

        return $this->consulter($panierId);
    }

    public function vider(PanierId $panierId): void
    {
        $this->store->vider($panierId);
    }

    private function fixer(PanierId $panierId, string $offreId, int $quantite): Panier
    {
        if ($quantite < 1) {
            throw new \InvalidArgumentException('La quantité doit être positive.');
        }
        $offre = $this->catalogue->disponibilites([$offreId])[$offreId] ?? null;
        if (null === $offre || !$offre->active) {
            throw new OffreIndisponibleException();
        }
        if ($quantite > self::QUANTITE_MAX_PAR_OFFRE) {
            throw new QuantiteMaximaleException(self::QUANTITE_MAX_PAR_OFFRE);
        }
        if ($quantite > $offre->stockDisponible) {
            throw new StockInsuffisantException($offre->stockDisponible);
        }

        $this->store->definir($panierId, $offreId, $quantite);

        return $this->consulter($panierId);
    }

    private function quantiteMax(OffreDisponibilite $offre): int
    {
        return max(0, min(self::QUANTITE_MAX_PAR_OFFRE, $offre->stockDisponible));
    }
}
