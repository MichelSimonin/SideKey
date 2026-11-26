<?php

declare(strict_types=1);

namespace App\Repository;

use App\Exception\CatalogueException;
use App\Service\Catalogue\Gestion\Identifiant;
use App\Service\Catalogue\Gestion\JeuAdmin;
use App\Service\Catalogue\Gestion\JeuResumeAdmin;
use App\Service\Catalogue\Gestion\JeuxAdmin;
use App\Service\Catalogue\Gestion\PageJeuxAdmin;
use App\Service\Catalogue\Gestion\SaisieJeu;
use App\Service\Catalogue\Gestion\Slugueur;
use App\Service\Catalogue\RechercheTitre;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;

/**
 * Jeux du back-office (SQL paramétré, DBAL). Chaque écriture (jeu, deux traductions, genres) est un bloc tout ou rien ; les
 * contraintes de la base (slug unique, PEGI, longueur des descriptions, clés étrangères) font foi en dernier recours.
 * Aucune valeur saisie n'est concaténée : recherche échappée comme dans le catalogue, filtres liés.
 */
final class JeuxAdminRepository implements JeuxAdmin
{
    public function __construct(private readonly Connection $connexion)
    {
    }

    public function lister(?string $recherche, ?bool $actif, int $page): PageJeuxAdmin
    {
        $page = max(1, min($page, 10_000));
        $conditions = '';
        $params = [];
        $types = [];
        if (null !== $recherche && '' !== trim($recherche)) {
            $conditions .= " AND lower(translate(j.titre, :accents, :sansAccent)) LIKE :motif ESCAPE '\\'";
            $params['accents'] = RechercheTitre::ACCENTUEES;
            $params['sansAccent'] = RechercheTitre::SANS_ACCENT;
            $params['motif'] = RechercheTitre::motifContient(mb_substr(trim($recherche), 0, 100));
        }
        if (null !== $actif) {
            $conditions .= ' AND j.actif = :actif';
            $params['actif'] = $actif;
            $types['actif'] = ParameterType::BOOLEAN;
        }

        $total = (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu j WHERE true $conditions", $params, $types);
        $params['limite'] = PageJeuxAdmin::PAR_PAGE;
        $params['decalage'] = ($page - 1) * PageJeuxAdmin::PAR_PAGE;
        $types['limite'] = ParameterType::INTEGER;
        $types['decalage'] = ParameterType::INTEGER;
        $lignes = $total > $params['decalage'] ? $this->connexion->fetchAllAssociative(
            <<<SQL
                SELECT j.id::text AS id, j.slug, j.titre, e.nom AS editeur, j.pegi, j.actif, j.jaquette,
                       (SELECT count(*) FROM offre o WHERE o.jeu_id = j.id)::int AS nombre_offres
                  FROM jeu j JOIN editeur e ON e.id = j.editeur_id
                 WHERE true $conditions
                 ORDER BY j.titre, j.id
                 LIMIT :limite OFFSET :decalage
                SQL,
            $params,
            $types,
        ) : [];

        return new PageJeuxAdmin(array_map(static fn (array $l): JeuResumeAdmin => new JeuResumeAdmin(
            (string) $l['id'], (string) $l['slug'], (string) $l['titre'], (string) $l['editeur'], (int) $l['pegi'],
            (bool) $l['actif'], null === $l['jaquette'] ? null : (string) $l['jaquette'], (int) $l['nombre_offres'],
        ), $lignes), $total, $page);
    }

    public function trouver(string $id): JeuAdmin
    {
        return $this->charger($this->connexion, Identifiant::exiger($id)) ?? throw CatalogueException::introuvable();
    }

    public function genres(): array
    {
        return array_map(
            static fn (array $g): array => ['slug' => (string) $g['slug'], 'libelle' => (string) $g['libelle']],
            $this->connexion->fetchAllAssociative(
                "SELECT g.slug, coalesce(t.libelle, g.slug) AS libelle FROM genre g LEFT JOIN genre_traduction t ON t.genre_id = g.id AND t.langue_code = 'fr' ORDER BY libelle, g.slug",
            ),
        );
    }

    public function slugLibre(string $slug): string
    {
        $candidat = $slug;
        for ($numero = 2; $numero <= 100; ++$numero) {
            if (false === $this->connexion->fetchOne('SELECT 1 FROM jeu WHERE slug = :slug', ['slug' => $candidat])) {
                return $candidat;
            }
            $candidat = Slugueur::variante($slug, $numero);
        }

        return Slugueur::variante($slug, random_int(1000, 999_999));   // 100 titres homonymes : on sort de la numérotation
    }

    public function creer(SaisieJeu $saisie, string $slug): JeuAdmin
    {
        $id = $this->connexion->transactional(function (Connection $c) use ($saisie, $slug): string {
            try {
                $id = (string) $c->fetchOne(
                    <<<'SQL'
                        INSERT INTO jeu (titre, slug, date_sortie, pegi, actif, editeur_id)
                        VALUES (:titre, :slug, CAST(:date AS date), :pegi, :actif, :editeur::uuid)
                        RETURNING id::text
                        SQL,
                    ['titre' => $saisie->titre, 'slug' => $slug, 'date' => $saisie->dateSortie, 'pegi' => $saisie->pegi, 'actif' => $saisie->actif, 'editeur' => $saisie->editeurId],
                    ['pegi' => ParameterType::INTEGER, 'actif' => ParameterType::BOOLEAN],
                );
            } catch (UniqueConstraintViolationException) {
                throw CatalogueException::doublon('error.slug_doublon');
            } catch (ForeignKeyConstraintViolationException) {
                throw CatalogueException::invalide(['editeurId' => 'error.invalid_value']);
            }
            $this->ecrireTraductionsEtGenres($c, $id, $saisie);

            return $id;
        });

        return $this->trouver($id);
    }

    public function modifier(string $id, SaisieJeu $saisie, ?string $slug): array
    {
        $id = Identifiant::exiger($id);
        $ancienSlug = $this->connexion->transactional(function (Connection $c) use ($id, $saisie, $slug): string {
            $ancien = $c->fetchOne('SELECT slug FROM jeu WHERE id = :id::uuid FOR UPDATE', ['id' => $id]);
            if (false === $ancien) {
                throw CatalogueException::introuvable();
            }
            try {
                $c->executeStatement(
                    <<<'SQL'
                        UPDATE jeu SET titre = :titre, slug = coalesce(CAST(:slug AS varchar), slug), date_sortie = CAST(:date AS date), pegi = :pegi,
                                       actif = :actif, editeur_id = :editeur::uuid, modifie_le = now()
                         WHERE id = :id::uuid
                        SQL,
                    ['titre' => $saisie->titre, 'slug' => $slug, 'date' => $saisie->dateSortie, 'pegi' => $saisie->pegi, 'actif' => $saisie->actif, 'editeur' => $saisie->editeurId, 'id' => $id],
                    ['pegi' => ParameterType::INTEGER, 'actif' => ParameterType::BOOLEAN],
                );
            } catch (UniqueConstraintViolationException) {
                throw CatalogueException::doublon('error.slug_doublon');
            } catch (ForeignKeyConstraintViolationException) {
                throw CatalogueException::invalide(['editeurId' => 'error.invalid_value']);
            }
            $this->ecrireTraductionsEtGenres($c, $id, $saisie);

            return (string) $ancien;
        });

        return ['jeu' => $this->trouver($id), 'ancienSlug' => $ancienSlug];
    }

    public function desactiver(string $id): string
    {
        $slug = $this->connexion->fetchOne('UPDATE jeu SET actif = false, modifie_le = now() WHERE id = :id::uuid RETURNING slug', ['id' => Identifiant::exiger($id)]);

        return false === $slug ? throw CatalogueException::introuvable() : (string) $slug;
    }

    public function definirJaquette(string $id, ?string $chemin): array
    {
        $ligne = $this->connexion->fetchAssociative(
            <<<'SQL'
                UPDATE jeu j SET jaquette = :chemin, modifie_le = now()
                  FROM (SELECT id, jaquette FROM jeu WHERE id = :id::uuid FOR UPDATE) AS avant
                 WHERE j.id = avant.id
                RETURNING j.slug, avant.jaquette AS ancien
                SQL,
            ['chemin' => $chemin, 'id' => Identifiant::exiger($id)],
        );
        if (false === $ligne) {
            throw CatalogueException::introuvable();
        }

        return ['slug' => (string) $ligne['slug'], 'ancien' => null === $ligne['ancien'] ? null : (string) $ligne['ancien']];
    }

    private function ecrireTraductionsEtGenres(Connection $c, string $id, SaisieJeu $saisie): void
    {
        foreach ($saisie->descriptions as $langue => $description) {
            $c->executeStatement(
                'INSERT INTO jeu_traduction (jeu_id, langue_code, description) VALUES (:jeu::uuid, :langue, :description)
                 ON CONFLICT (jeu_id, langue_code) DO UPDATE SET description = EXCLUDED.description',
                ['jeu' => $id, 'langue' => $langue, 'description' => $description],
            );
        }

        $c->executeStatement('DELETE FROM jeu_genre WHERE jeu_id = :jeu::uuid', ['jeu' => $id]);
        if ([] === $saisie->genres) {
            return;
        }
        $inseres = $c->executeStatement(
            'INSERT INTO jeu_genre (jeu_id, genre_id) SELECT :jeu::uuid, g.id FROM genre g WHERE g.slug IN (:slugs)',
            ['jeu' => $id, 'slugs' => $saisie->genres],
            ['slugs' => ArrayParameterType::STRING],
        );
        if ($inseres !== \count($saisie->genres)) {
            throw CatalogueException::invalide(['genres' => 'error.invalid_value']);   // un genre inconnu : tout est annulé
        }
    }

    private function charger(Connection $c, string $id): ?JeuAdmin
    {
        $jeu = $c->fetchAssociative(
            <<<'SQL'
                SELECT j.id::text AS id, j.slug, j.titre, j.date_sortie::text AS date_sortie, j.pegi, j.actif, j.jaquette,
                       j.editeur_id::text AS editeur_id, e.nom AS editeur
                  FROM jeu j JOIN editeur e ON e.id = j.editeur_id
                 WHERE j.id = :id::uuid
                SQL,
            ['id' => $id],
        );
        if (false === $jeu) {
            return null;
        }
        $descriptions = ['fr' => '', 'en' => ''];
        foreach ($c->fetchAllAssociative('SELECT langue_code, description FROM jeu_traduction WHERE jeu_id = :id::uuid', ['id' => $id]) as $t) {
            $descriptions[trim((string) $t['langue_code'])] = (string) $t['description'];
        }
        $genres = array_map('strval', $c->fetchFirstColumn(
            'SELECT g.slug FROM jeu_genre jg JOIN genre g ON g.id = jg.genre_id WHERE jg.jeu_id = :id::uuid ORDER BY g.slug',
            ['id' => $id],
        ));

        return new JeuAdmin(
            (string) $jeu['id'], (string) $jeu['slug'], (string) $jeu['titre'], null === $jeu['date_sortie'] ? null : (string) $jeu['date_sortie'],
            (int) $jeu['pegi'], (bool) $jeu['actif'], null === $jeu['jaquette'] ? null : (string) $jeu['jaquette'],
            (string) $jeu['editeur_id'], (string) $jeu['editeur'], ['fr' => $descriptions['fr'], 'en' => $descriptions['en']], $genres,
            OffresLecture::pourJeu($c, $id),
        );
    }
}
