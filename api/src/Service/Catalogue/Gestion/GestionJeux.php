<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Service\Audit\JournalAudit;
use App\Service\Catalogue\CatalogueCache;

/**
 * Gestion des jeux (US-50, F-09) : création, modification (titre, slug, date, PEGI, éditeur, descriptions FR et EN, genres),
 * désactivation. « Suppression logique » : un jeu désactivé sort du catalogue mais ses offres, ses clés et l'historique des
 * commandes restent (RG-04, RG-05).
 *
 * Après chaque écriture : trace dans le journal d'audit (sans donnée sensible) et invalidation du cache du catalogue, la fiche
 * du jeu (ancien et nouveau slug) et toutes les listes (CDC §9.8). C'est l'appelant promis par CatalogueCache depuis F-01.
 */
final class GestionJeux
{
    public function __construct(
        private readonly JeuxAdmin $jeux,
        private readonly CatalogueCache $cache,
        private readonly JournalAudit $audit,
    ) {
    }

    public function lister(?string $recherche, ?bool $actif, int $page): PageJeuxAdmin
    {
        return $this->jeux->lister($recherche, $actif, $page);
    }

    public function trouver(string $id): JeuAdmin
    {
        return $this->jeux->trouver($id);
    }

    /** @return list<array{slug: string, libelle: string}> */
    public function genres(): array
    {
        return $this->jeux->genres();
    }

    public function creer(SaisieJeu $saisie, string $acteurId, ?string $ip): JeuAdmin
    {
        // Slug saisi : tel quel (un doublon est refusé, 409) ; sinon dérivé du titre et numéroté s'il est déjà pris.
        $slug = $saisie->slug ?? $this->jeux->slugLibre(Slugueur::depuis($saisie->titre));
        $jeu = $this->jeux->creer($saisie, $slug);

        $this->audit->tracer(JournalAudit::CATALOGUE_CREATION, 'jeu', $jeu->id, $acteurId, $ip);
        $this->invalider($jeu->slug);

        return $jeu;
    }

    public function modifier(string $id, SaisieJeu $saisie, string $acteurId, ?string $ip): JeuAdmin
    {
        ['jeu' => $jeu, 'ancienSlug' => $ancien] = $this->jeux->modifier($id, $saisie, $saisie->slug);

        $this->audit->tracer(JournalAudit::CATALOGUE_MODIFICATION, 'jeu', $jeu->id, $acteurId, $ip);
        $this->invalider($ancien, $jeu->slug);

        return $jeu;
    }

    public function desactiver(string $id, string $acteurId, ?string $ip): void
    {
        $slug = $this->jeux->desactiver($id);

        $this->audit->tracer(JournalAudit::CATALOGUE_SUPPRESSION, 'jeu', Identifiant::exiger($id), $acteurId, $ip);
        $this->invalider($slug);
    }

    private function invalider(string ...$slugs): void
    {
        foreach (array_unique($slugs) as $slug) {
            $this->cache->invaliderJeu($slug);
        }
        $this->cache->invaliderListes();
    }
}
