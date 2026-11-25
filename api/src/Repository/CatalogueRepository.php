<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\Catalogue\CatalogueRecherche;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\JeuFiche;
use App\Service\Catalogue\JeuResume;
use App\Service\Catalogue\Langue;
use App\Service\Catalogue\PageJeux;
use App\Service\Catalogue\RechercheTitre;
use App\Service\Catalogue\ValeursFiltres;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Lecture du catalogue (US-01 à US-05). SQL PARAMÉTRÉ uniquement : aucune valeur du client n'est concaténée.
 *  - les critères sont des fragments constants de ce fichier, ajoutés seulement quand le critère est présent ;
 *    leurs valeurs sont des paramètres liés (listes comprises) ;
 *  - le tri est une clause constante choisie dans la liste blanche TriCatalogue ;
 *  - LIMIT et OFFSET sont des entiers bornés (20 par page, page 10 000 au plus) ;
 *  - le LIKE de la recherche reçoit un motif dont les jokers sont échappés (RechercheTitre).
 * Projections explicites : jamais de SELECT *, jamais d'identifiant de clé de licence (seulement un décompte).
 */
final class CatalogueRepository implements CatalogueRecherche
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    public function rechercher(FiltresCatalogue $filtres, Langue $langue): PageJeux
    {
        [$conditions, $params, $types] = $this->criteres($filtres);

        $base = <<<SQL
            WITH b AS (
                SELECT j.id, j.slug, j.titre, j.pegi, j.jaquette, j.date_sortie,
                       min(o.prix_ttc)                      AS prix_min,
                       array_agg(DISTINCT p.code ORDER BY p.code) AS plateformes,
                       coalesce(sum(s.stock), 0) > 0         AS disponible,
                       av.note_moyenne, coalesce(av.nombre_avis, 0) AS nombre_avis
                  FROM jeu j
                  JOIN offre o ON o.jeu_id = j.id AND o.actif
                  JOIN plateforme p ON p.id = o.plateforme_id
                  LEFT JOIN LATERAL (
                      SELECT count(*) AS stock FROM cle_licence k WHERE k.offre_id = o.id AND k.statut = 'DISPONIBLE'
                  ) s ON true
                  LEFT JOIN LATERAL (
                      SELECT round(avg(a.note), 1) AS note_moyenne, count(*)::int AS nombre_avis
                        FROM avis a WHERE a.jeu_id = j.id AND a.statut_moderation = 'PUBLIE'
                  ) av ON true
                 WHERE j.actif {$conditions}
                 GROUP BY j.id, av.note_moyenne, av.nombre_avis
            )
            SQL;

        $total = (int) $this->connexion->fetchOne($base.' SELECT count(*) FROM b', $params, $types);

        // Hors du dernier résultat, inutile de lire la page : la liste est vide.
        $lignes = [];
        if ($filtres->decalage() < $total) {
            $params['limite'] = FiltresCatalogue::PAR_PAGE;
            $params['decalage'] = $filtres->decalage();
            $types['limite'] = ParameterType::INTEGER;
            $types['decalage'] = ParameterType::INTEGER;
            $lignes = $this->connexion->fetchAllAssociative(
                $base.' SELECT b.slug, b.titre, b.pegi, b.jaquette, (b.prix_min * 100)::integer AS prix_min_centimes, b.plateformes::text AS plateformes,'
                .' b.disponible, b.note_moyenne, b.nombre_avis FROM b ORDER BY '.$filtres->tri->clauseSql().' LIMIT :limite OFFSET :decalage',
                $params,
                $types,
            );
        }

        return new PageJeux(array_map(static fn (array $l): JeuResume => new JeuResume(
            (string) $l['slug'], (string) $l['titre'], (int) $l['pegi'], null === $l['jaquette'] ? null : (string) $l['jaquette'],
            explode(',', trim((string) $l['plateformes'], '{}')), (int) $l['prix_min_centimes'], (bool) $l['disponible'],
            null === $l['note_moyenne'] ? null : (float) $l['note_moyenne'], (int) $l['nombre_avis'],
        ), $lignes), $total, $filtres->page);
    }

    public function fiche(string $slug, Langue $langue): ?JeuFiche
    {
        $jeu = $this->connexion->fetchAssociative(
            <<<'SQL'
                SELECT j.id::text AS id, j.slug, j.titre, j.pegi, j.jaquette, j.date_sortie::text AS date_sortie, e.nom AS editeur,
                       coalesce(t1.description, t2.description, '') AS description,
                       av.note_moyenne, coalesce(av.nombre_avis, 0) AS nombre_avis
                  FROM jeu j
                  JOIN editeur e ON e.id = j.editeur_id
                  LEFT JOIN jeu_traduction t1 ON t1.jeu_id = j.id AND t1.langue_code = :langue
                  LEFT JOIN jeu_traduction t2 ON t2.jeu_id = j.id AND t2.langue_code = :repli
                  LEFT JOIN LATERAL (
                      SELECT round(avg(a.note), 1) AS note_moyenne, count(*)::int AS nombre_avis
                        FROM avis a WHERE a.jeu_id = j.id AND a.statut_moderation = 'PUBLIE'
                  ) av ON true
                 WHERE j.slug = :slug AND j.actif
                SQL,
            ['slug' => $slug, 'langue' => $langue->value, 'repli' => $langue->repli()->value],
        );
        if (false === $jeu) {
            return null;
        }

        $genres = $this->connexion->fetchAllAssociative(
            <<<'SQL'
                SELECT g.slug, coalesce(t1.libelle, t2.libelle, g.slug) AS libelle
                  FROM jeu_genre jg
                  JOIN genre g ON g.id = jg.genre_id
                  LEFT JOIN genre_traduction t1 ON t1.genre_id = g.id AND t1.langue_code = :langue
                  LEFT JOIN genre_traduction t2 ON t2.genre_id = g.id AND t2.langue_code = :repli
                 WHERE jg.jeu_id = :jeu::uuid
                 ORDER BY libelle, g.slug
                SQL,
            ['jeu' => $jeu['id'], 'langue' => $langue->value, 'repli' => $langue->repli()->value],
        );

        $offres = $this->connexion->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id::text AS offre_id, p.code, p.libelle, (o.prix_ttc * 100)::integer AS prix_centimes,
                       (SELECT count(*)::int FROM cle_licence k WHERE k.offre_id = o.id AND k.statut = 'DISPONIBLE') AS stock
                  FROM offre o
                  JOIN plateforme p ON p.id = o.plateforme_id
                 WHERE o.jeu_id = :jeu::uuid AND o.actif
                 ORDER BY o.prix_ttc, p.code
                SQL,
            ['jeu' => $jeu['id']],
        );

        return new JeuFiche(
            (string) $jeu['slug'], (string) $jeu['titre'], (string) $jeu['description'], (int) $jeu['pegi'],
            null === $jeu['jaquette'] ? null : (string) $jeu['jaquette'], null === $jeu['date_sortie'] ? null : (string) $jeu['date_sortie'],
            (string) $jeu['editeur'],
            array_map(static fn (array $g): array => ['slug' => (string) $g['slug'], 'libelle' => (string) $g['libelle']], $genres),
            array_map(static fn (array $o): array => ['offreId' => (string) $o['offre_id'], 'plateforme' => (string) $o['code'],
                'plateformeLibelle' => (string) $o['libelle'], 'prixCentimes' => (int) $o['prix_centimes'], 'stock' => (int) $o['stock']], $offres),
            null === $jeu['note_moyenne'] ? null : (float) $jeu['note_moyenne'],
            (int) $jeu['nombre_avis'],
        );
    }

    public function valeursFiltres(Langue $langue): ValeursFiltres
    {
        $plateformes = $this->connexion->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT p.code, p.libelle
                  FROM plateforme p
                  JOIN offre o ON o.plateforme_id = p.id AND o.actif
                  JOIN jeu j ON j.id = o.jeu_id AND j.actif
                 ORDER BY p.libelle, p.code
                SQL,
        );
        $genres = $this->connexion->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT g.slug, coalesce(t1.libelle, t2.libelle, g.slug) AS libelle
                  FROM genre g
                  JOIN jeu_genre jg ON jg.genre_id = g.id
                  JOIN jeu j ON j.id = jg.jeu_id AND j.actif
                  JOIN offre o ON o.jeu_id = j.id AND o.actif
                  LEFT JOIN genre_traduction t1 ON t1.genre_id = g.id AND t1.langue_code = :langue
                  LEFT JOIN genre_traduction t2 ON t2.genre_id = g.id AND t2.langue_code = :repli
                 ORDER BY libelle, g.slug
                SQL,
            ['langue' => $langue->value, 'repli' => $langue->repli()->value],
        );
        $prix = $this->connexion->fetchAssociative(
            'SELECT coalesce(floor(min(o.prix_ttc) * 100), 0)::integer AS min, coalesce(ceil(max(o.prix_ttc) * 100), 0)::integer AS max
               FROM offre o JOIN jeu j ON j.id = o.jeu_id AND j.actif WHERE o.actif',
        );

        return new ValeursFiltres(
            array_map(static fn (array $p): array => ['code' => (string) $p['code'], 'libelle' => (string) $p['libelle']], $plateformes),
            array_map(static fn (array $g): array => ['slug' => (string) $g['slug'], 'libelle' => (string) $g['libelle']], $genres),
            (int) ($prix['min'] ?? 0),
            (int) ($prix['max'] ?? 0),
        );
    }

    /**
     * Fragments de WHERE et paramètres liés pour les critères présents.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function criteres(FiltresCatalogue $f): array
    {
        $sql = '';
        $params = [];
        $types = [];

        if (null !== $f->recherche) {
            $sql .= " AND lower(translate(j.titre, :accents, :sansAccent)) LIKE :motif ESCAPE '\\'";
            $params['accents'] = RechercheTitre::ACCENTUEES;
            $params['sansAccent'] = RechercheTitre::SANS_ACCENT;
            $params['motif'] = RechercheTitre::motifContient($f->recherche);
        }
        if ([] !== $f->plateformes) {
            $sql .= ' AND p.code IN (:plateformes)';
            $params['plateformes'] = $f->plateformes;
            $types['plateformes'] = ArrayParameterType::STRING;
        }
        if ([] !== $f->genres) {
            $sql .= ' AND EXISTS (SELECT 1 FROM jeu_genre jg JOIN genre g ON g.id = jg.genre_id WHERE jg.jeu_id = j.id AND g.slug IN (:genres))';
            $params['genres'] = $f->genres;
            $types['genres'] = ArrayParameterType::STRING;
        }
        if (null !== $f->prixMinCentimes) {
            $sql .= ' AND o.prix_ttc * 100 >= :prixMin';
            $params['prixMin'] = $f->prixMinCentimes;
            $types['prixMin'] = ParameterType::INTEGER;
        }
        if (null !== $f->prixMaxCentimes) {
            $sql .= ' AND o.prix_ttc * 100 <= :prixMax';
            $params['prixMax'] = $f->prixMaxCentimes;
            $types['prixMax'] = ParameterType::INTEGER;
        }
        if (null !== $f->pegi) {
            $sql .= ' AND j.pegi <= :pegi';
            $params['pegi'] = $f->pegi;
            $types['pegi'] = ParameterType::INTEGER;
        }

        return [$sql, $params, $types];
    }
}
