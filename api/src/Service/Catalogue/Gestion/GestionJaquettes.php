<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Service\Audit\JournalAudit;
use App\Service\Catalogue\CatalogueCache;

/**
 * Jaquette d'un jeu (F-09) : téléversement (remplace la précédente) et retrait.
 *
 * Ordre voulu : valider, écrire le nouveau fichier, puis mettre la base à jour ; si la base échoue, le fichier neuf est retiré
 * (pas d'orphelin). L'ancien fichier n'est supprimé qu'après la mise à jour réussie : un échec laisse l'ancienne jaquette en place.
 */
final class GestionJaquettes
{
    public function __construct(
        private readonly JeuxAdmin $jeux,
        private readonly StockageJaquettes $stockage,
        private readonly CatalogueCache $cache,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * @throws \App\Exception\JaquetteInvalideException image refusée (422)
     * @throws \App\Exception\CatalogueException         jeu introuvable (404)
     */
    public function definir(string $jeuId, string $contenu, string $acteurId, ?string $ip): JeuAdmin
    {
        $jeuId = Identifiant::exiger($jeuId);   // avant toute écriture : un identifiant forgé ne laisse aucun fichier
        ValidationJaquette::valider($contenu);

        $chemin = $this->stockage->ecrire($contenu);
        try {
            ['slug' => $slug, 'ancien' => $ancien] = $this->jeux->definirJaquette($jeuId, $chemin);
        } catch (\Throwable $e) {
            $this->stockage->supprimer($chemin);

            throw $e;
        }

        if (null !== $ancien) {
            $this->stockage->supprimer($ancien);
        }
        $this->audit->tracer(JournalAudit::CATALOGUE_MODIFICATION, 'jeu_jaquette', $jeuId, $acteurId, $ip);
        $this->invalider($slug);

        return $this->jeux->trouver($jeuId);
    }

    public function retirer(string $jeuId, string $acteurId, ?string $ip): void
    {
        $jeuId = Identifiant::exiger($jeuId);
        ['slug' => $slug, 'ancien' => $ancien] = $this->jeux->definirJaquette($jeuId, null);

        if (null !== $ancien) {
            $this->stockage->supprimer($ancien);
        }
        $this->audit->tracer(JournalAudit::CATALOGUE_SUPPRESSION, 'jeu_jaquette', $jeuId, $acteurId, $ip);
        $this->invalider($slug);
    }

    private function invalider(string $slug): void
    {
        $this->cache->invaliderJeu($slug);
        $this->cache->invaliderListes();
    }
}
