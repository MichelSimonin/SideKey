<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;

/** Accès aux jeux pour le back-office (couche données). Implémentation : Repository/JeuxAdminRepository. Aucune autorisation ici. */
interface JeuxAdmin
{
    /** Liste paginée, jeux inactifs compris ; $actif null = tous, true ou false = filtre. */
    public function lister(?string $recherche, ?bool $actif, int $page): PageJeuxAdmin;

    /** @throws CatalogueException introuvable (404) */
    public function trouver(string $id): JeuAdmin;

    /** @return list<array{slug: string, libelle: string}> genres proposés à l'affectation (libellé français) */
    public function genres(): array;

    /** Un slug libre : $slug, ou sa première variante numérotée non utilisée. */
    public function slugLibre(string $slug): string;

    /**
     * Crée le jeu, ses deux traductions et ses genres, tout ou rien.
     *
     * @throws CatalogueException slug déjà pris (409), éditeur ou genre inconnu (422)
     */
    public function creer(SaisieJeu $saisie, string $slug): JeuAdmin;

    /**
     * Modifie le jeu, ses traductions et ses genres, tout ou rien. Le slug existant est conservé si $slug est null.
     *
     * @return array{jeu: JeuAdmin, ancienSlug: string}
     *
     * @throws CatalogueException introuvable (404), slug déjà pris (409), éditeur ou genre inconnu (422)
     */
    public function modifier(string $id, SaisieJeu $saisie, ?string $slug): array;

    /**
     * Suppression logique : le jeu sort du catalogue (actif = faux), ses offres et son historique restent.
     *
     * @return string slug du jeu
     *
     * @throws CatalogueException introuvable (404)
     */
    public function desactiver(string $id): string;

    /**
     * Enregistre le chemin de la jaquette (null : aucune) et renvoie l'ancien chemin.
     *
     * @return array{slug: string, ancien: ?string}
     *
     * @throws CatalogueException introuvable (404)
     */
    public function definirJaquette(string $id, ?string $chemin): array;
}
