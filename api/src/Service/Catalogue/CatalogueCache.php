<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/**
 * Cache du catalogue (CDC §9.8). Implémentation : Infrastructure/Redis/RedisCatalogueCache.
 *
 *   cache:jeu:{slug}:{langue}                String JSON, TTL 5 min : une fiche
 *   cache:jeux:v{n}:{langue}:{empreinte}     String JSON, TTL 5 min : une liste filtrée (n = version des listes)
 *   cache:filtres:v{n}:{langue}              String JSON, TTL 5 min : les valeurs des filtres
 *   cache:jeux:version                       compteur : invaliderListes() l'incrémente, les anciennes listes expirent seules
 *
 * Le cache n'est qu'une optimisation : si Redis ne répond pas, les lectures échouent en silence (null) et
 * le service interroge PostgreSQL ; le catalogue reste disponible (DICP : disponibilité 2/4).
 *
 * INVALIDATION : toute écriture qui modifie le catalogue (jeu, traduction, genre, offre, prix, import de clés,
 * réservation ou vente de clés) doit appeler invaliderJeu() pour le jeu concerné ET invaliderListes() : le prix
 * minimum, la disponibilité et la note figurent dans les listes. Le TTL de 5 minutes n'est qu'un filet de sécurité.
 */
interface CatalogueCache
{
    public const TTL = 300;   // 5 minutes

    public function fiche(string $slug, Langue $langue): ?JeuFiche;

    public function memoriserFiche(string $slug, Langue $langue, JeuFiche $fiche): void;

    public function liste(FiltresCatalogue $filtres, Langue $langue): ?PageJeux;

    public function memoriserListe(FiltresCatalogue $filtres, Langue $langue, PageJeux $page): void;

    public function valeursFiltres(Langue $langue): ?ValeursFiltres;

    public function memoriserValeursFiltres(Langue $langue, ValeursFiltres $valeurs): void;

    /** Retire la fiche d'un jeu, dans toutes les langues. Le slug est validé avant d'entrer dans une clé. */
    public function invaliderJeu(string $slug): void;

    /** Rend caduques toutes les listes et les valeurs de filtres (O(1) : changement de version). */
    public function invaliderListes(): void;
}
