<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Exception\CatalogueException;
use App\Exception\ConflitOffreException;
use App\Repository\EditeursRepository;
use App\Repository\JeuxAdminRepository;
use App\Repository\OffresAdminRepository;
use App\Repository\PlateformesRepository;
use App\Service\Catalogue\Gestion\SaisieJeu;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Dépôts du back-office catalogue (F-09) contre la base sidekey_test, avec le rôle applicatif sk_app : ce test prouve aussi
 * que les droits SGBD de ce rôle suffisent au CRUD (et seulement à lui). Tout ce qui est créé porte le préfixe « zz-test » et
 * est retiré à la fin ; le jeu d'essai n'est jamais modifié.
 */
#[Group('database')]
final class CatalogueAdminRepositoryTest extends KernelTestCase
{
    private const DESCRIPTION = 'Une description de test suffisamment longue.';

    private Connection $connexion;
    private EditeursRepository $editeurs;
    private PlateformesRepository $plateformes;
    private JeuxAdminRepository $jeux;
    private OffresAdminRepository $offres;

    protected function setUp(): void
    {
        self::bootKernel();
        $connexion = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $this->connexion = $connexion;
        $this->editeurs = new EditeursRepository($connexion);
        $this->plateformes = new PlateformesRepository($connexion);
        $this->jeux = new JeuxAdminRepository($connexion);
        $this->offres = new OffresAdminRepository($connexion);
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    private function nettoyer(): void
    {
        $c = $this->connexion;
        $c->executeStatement("DELETE FROM offre WHERE jeu_id IN (SELECT id FROM jeu WHERE slug LIKE 'zz-test%')");
        $c->executeStatement("DELETE FROM jeu_genre WHERE jeu_id IN (SELECT id FROM jeu WHERE slug LIKE 'zz-test%')");
        $c->executeStatement("DELETE FROM jeu_traduction WHERE jeu_id IN (SELECT id FROM jeu WHERE slug LIKE 'zz-test%')");
        $c->executeStatement("DELETE FROM jeu WHERE slug LIKE 'zz-test%'");
        $c->executeStatement("DELETE FROM editeur WHERE nom LIKE 'ZZ Test%'");
        $c->executeStatement("DELETE FROM plateforme WHERE code LIKE 'ZZTEST%'");
    }

    /** @param list<string>|null $genres */
    private function saisie(string $editeurId, string $titre = 'ZZ Test Jeu', ?string $slug = null, ?array $genres = null, string $fr = self::DESCRIPTION, string $en = self::DESCRIPTION, bool $actif = true): SaisieJeu
    {
        return new SaisieJeu($titre, $slug, '2025-03-14', 16, $editeurId, $actif, ['fr' => $fr, 'en' => $en], $genres ?? []);
    }

    /** @return list<string> */
    private function genres(int $nombre): array
    {
        return array_map('strval', $this->connexion->fetchFirstColumn('SELECT slug FROM genre ORDER BY slug LIMIT '.$nombre));
    }

    private function jeuDeTest(string $slug = 'zz-test-jeu', string $titre = 'ZZ Test Jeu'): string
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur '.$slug);

        return $this->jeux->creer($this->saisie($editeur->id, $titre), $slug)->id;
    }

    // --- Éditeurs --------------------------------------------------------------------------------

    public function testCrudEditeursEtComptageDesJeux(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');
        self::assertSame(0, $editeur->nombreJeux);
        self::assertContains($editeur->id, array_map(static fn ($e): string => $e->id, $this->editeurs->lister()));

        $renomme = $this->editeurs->modifier($editeur->id, 'ZZ Test Éditeur Renommé');
        self::assertSame('ZZ Test Éditeur Renommé', $renomme->nom);

        $this->jeux->creer($this->saisie($editeur->id), 'zz-test-jeu');
        self::assertSame(1, $this->editeurs->trouver($editeur->id)->nombreJeux);
        self::assertSame(['zz-test-jeu'], $this->editeurs->slugsDesJeux($editeur->id));

        $this->editeurs->supprimer($this->editeurs->creer('ZZ Test Sans Jeu')->id);
    }

    public function testNomDEditeurDejaPrisEtSuppressionBloqueeParLesJeux(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');
        $this->jeux->creer($this->saisie($editeur->id), 'zz-test-jeu');

        try {
            $this->editeurs->creer('ZZ Test Éditeur');
            self::fail('Un 409 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame(409, $e->codeHttp());
            self::assertSame('error.editeur_doublon', $e->cleMessage());
        }
        try {
            $this->editeurs->supprimer($editeur->id);
            self::fail('Un 409 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame('error.catalogue_utilisee', $e->cleMessage(), 'la clé étrangère RESTRICT protège les jeux');
        }
        self::assertSame($editeur->id, $this->editeurs->trouver($editeur->id)->id, 'l\'éditeur existe toujours');
    }

    public function testEditeurInconnuOuIdentifiantForge(): void
    {
        foreach (['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', "1' OR '1'='1", '../../etc/passwd', ''] as $id) {
            foreach ([
                fn () => $this->editeurs->trouver($id),
                fn () => $this->editeurs->modifier($id, 'ZZ Test'),
                fn () => $this->editeurs->supprimer($id),
            ] as $appel) {
                try {
                    $appel();
                    self::fail('Un 404 était attendu pour '.$id);
                } catch (CatalogueException $e) {
                    self::assertSame(404, $e->codeHttp());
                }
            }
        }
    }

    // --- Plateformes -----------------------------------------------------------------------------

    public function testCrudPlateformesLeCodeNeChangePas(): void
    {
        $plateforme = $this->plateformes->creer('ZZTEST', 'ZZ Test');
        self::assertSame(0, $plateforme->nombreOffres);

        $modifiee = $this->plateformes->modifier($plateforme->id, 'ZZ Test Renommée');
        self::assertSame('ZZ Test Renommée', $modifiee->libelle);
        self::assertSame('ZZTEST', $modifiee->code, 'le code est immuable : seul le libellé se modifie');

        try {
            $this->plateformes->creer('ZZTEST', 'Autre');
            self::fail('Un 409 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame('error.plateforme_doublon', $e->cleMessage());
        }

        $this->plateformes->supprimer($plateforme->id);
        $this->expectException(CatalogueException::class);
        $this->plateformes->trouver($plateforme->id);
    }

    public function testUnePlateformeUtiliseeParUneOffreNeSeSupprimePas(): void
    {
        $plateforme = $this->plateformes->creer('ZZTEST', 'ZZ Test');
        $jeu = $this->jeuDeTest();
        $this->offres->creer($jeu, $plateforme->id, '9.99', 5);

        self::assertSame(1, $this->plateformes->trouver($plateforme->id)->nombreOffres);
        self::assertSame(['zz-test-jeu'], $this->plateformes->slugsDesJeux($plateforme->id));
        try {
            $this->plateformes->supprimer($plateforme->id);
            self::fail('Un 409 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame('error.catalogue_utilisee', $e->cleMessage());
        }
    }

    public function testLaBaseRefuseUnCodeDePlateformeMalForme(): void
    {
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/SQLSTATE\[23514\]/');   // violation de contrainte CHECK
        $this->plateformes->creer('zz test', 'Invalide');   // ck_plateforme_code : ^[A-Z0-9_]+$
    }

    // --- Jeux ------------------------------------------------------------------------------------

    public function testCreationDUnJeuAvecTraductionsEtGenres(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');
        $genres = $this->genres(2);

        $jeu = $this->jeux->creer($this->saisie($editeur->id, 'ZZ Test Jeu', genres: $genres, fr: 'Description française du jeu.', en: 'English description of the game.'), 'zz-test-jeu');

        self::assertSame('zz-test-jeu', $jeu->slug);
        self::assertSame('2025-03-14', $jeu->dateSortie);
        self::assertSame(16, $jeu->pegi);
        self::assertTrue($jeu->actif);
        self::assertNull($jeu->jaquette);
        self::assertSame($editeur->id, $jeu->editeurId);
        self::assertSame(['fr' => 'Description française du jeu.', 'en' => 'English description of the game.'], $jeu->descriptions);
        self::assertSame($genres, $jeu->genres);
        self::assertSame([], $jeu->offres);
    }

    public function testModificationRemplaceTraductionsEtGenresEtGardeLeSlugSiAbsent(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');
        [$a, $b, $c] = $this->genres(3);
        $jeu = $this->jeux->creer($this->saisie($editeur->id, genres: [$a, $b]), 'zz-test-jeu');

        ['jeu' => $modifie, 'ancienSlug' => $ancien] = $this->jeux->modifier($jeu->id, $this->saisie($editeur->id, 'ZZ Test Jeu 2', genres: [$c], fr: 'Nouvelle description FR.', en: 'New EN description.', actif: false), null);

        self::assertSame('zz-test-jeu', $ancien);
        self::assertSame('zz-test-jeu', $modifie->slug, 'slug absent : conservé (les liens existants restent valides)');
        self::assertSame('ZZ Test Jeu 2', $modifie->titre);
        self::assertFalse($modifie->actif);
        self::assertSame([$c], $modifie->genres, 'genres remplacés, pas cumulés');
        self::assertSame('Nouvelle description FR.', $modifie->descriptions['fr']);
        self::assertSame(2, (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu_traduction WHERE jeu_id = :j::uuid', ['j' => $jeu->id]), 'pas de traduction en double');

        ['jeu' => $renomme] = $this->jeux->modifier($jeu->id, $this->saisie($editeur->id), 'zz-test-jeu-renomme');
        self::assertSame('zz-test-jeu-renomme', $renomme->slug);
    }

    public function testUnSlugDejaPrisEstRefuseEtRienNEstModifie(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');
        $premier = $this->jeux->creer($this->saisie($editeur->id, 'ZZ Test Premier'), 'zz-test-premier');
        $second = $this->jeux->creer($this->saisie($editeur->id, 'ZZ Test Second'), 'zz-test-second');

        foreach ([
            fn () => $this->jeux->creer($this->saisie($editeur->id, 'ZZ Test Autre'), 'zz-test-premier'),
            fn () => $this->jeux->modifier($second->id, $this->saisie($editeur->id, 'ZZ Test Modifié'), 'zz-test-premier'),
        ] as $appel) {
            try {
                $appel();
                self::fail('Un 409 était attendu.');
            } catch (CatalogueException $e) {
                self::assertSame('error.slug_doublon', $e->cleMessage());
            }
        }
        self::assertSame('ZZ Test Second', $this->jeux->trouver($second->id)->titre, 'la modification refusée n\'a rien écrit');
        self::assertSame('ZZ Test Premier', $this->jeux->trouver($premier->id)->titre);
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu WHERE titre = 'ZZ Test Autre'"));
    }

    public function testLEcritureEstToutOuRien(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');

        // Genre inconnu : le jeu et ses traductions, déjà insérés dans la transaction, sont annulés.
        try {
            $this->jeux->creer($this->saisie($editeur->id, genres: ['genre-qui-nexiste-pas']), 'zz-test-jeu');
            self::fail('Un 422 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame(422, $e->codeHttp());
            self::assertArrayHasKey('genres', $e->erreursChamps());
        }
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu WHERE slug = 'zz-test-jeu'"));
        self::assertSame(0, (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu_traduction t LEFT JOIN jeu j ON j.id = t.jeu_id WHERE j.id IS NULL'));

        // Éditeur inconnu.
        try {
            $this->jeux->creer($this->saisie('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'), 'zz-test-jeu');
            self::fail('Un 422 était attendu.');
        } catch (CatalogueException $e) {
            self::assertArrayHasKey('editeurId', $e->erreursChamps());
        }

        // Modification avec un genre inconnu : titre et genres d'origine conservés.
        $jeu = $this->jeux->creer($this->saisie($editeur->id, genres: $this->genres(1)), 'zz-test-jeu');
        try {
            $this->jeux->modifier($jeu->id, $this->saisie($editeur->id, 'ZZ Test Changé', genres: ['inconnu']), null);
            self::fail('Un 422 était attendu.');
        } catch (CatalogueException) {
            // attendu
        }
        $apres = $this->jeux->trouver($jeu->id);
        self::assertSame('ZZ Test Jeu', $apres->titre);
        self::assertSame($this->genres(1), $apres->genres);
    }

    public function testSlugLibreNumerote(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');
        self::assertSame('zz-test-jeu', $this->jeux->slugLibre('zz-test-jeu'));

        $this->jeux->creer($this->saisie($editeur->id), 'zz-test-jeu');
        self::assertSame('zz-test-jeu-2', $this->jeux->slugLibre('zz-test-jeu'));

        $this->jeux->creer($this->saisie($editeur->id), 'zz-test-jeu-2');
        self::assertSame('zz-test-jeu-3', $this->jeux->slugLibre('zz-test-jeu'));
    }

    public function testDesactivationEstUneSuppressionLogique(): void
    {
        $id = $this->jeuDeTest();
        $plateforme = (string) $this->connexion->fetchOne('SELECT id::text FROM plateforme ORDER BY code LIMIT 1');
        $this->offres->creer($id, $plateforme, '9.99', 5);

        self::assertSame('zz-test-jeu', $this->jeux->desactiver($id));

        $jeu = $this->jeux->trouver($id);
        self::assertFalse($jeu->actif);
        self::assertCount(1, $jeu->offres, 'les offres sont conservées');
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu j JOIN offre o ON o.jeu_id = j.id WHERE j.slug = 'zz-test-jeu' AND j.actif"));
    }

    public function testListeFiltreRechercheSansAccentNiCasseEtPagine(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');
        $this->jeux->creer($this->saisie($editeur->id, 'ZZ Test Étoile Noire'), 'zz-test-etoile');
        $this->jeux->creer($this->saisie($editeur->id, 'ZZ Test Lune', actif: false), 'zz-test-lune');

        $page = $this->jeux->lister('ETOILE noire', null, 1);
        self::assertSame(1, $page->total, 'recherche insensible à la casse et aux accents');
        self::assertSame('zz-test-etoile', $page->jeux[0]->slug);

        self::assertSame(['zz-test-lune'], array_map(static fn ($j): string => $j->slug, $this->jeux->lister('zz test', false, 1)->jeux), 'les jeux inactifs sont listés en back-office');
        self::assertSame(['zz-test-etoile'], array_map(static fn ($j): string => $j->slug, $this->jeux->lister('zz test', true, 1)->jeux));
        self::assertSame(2, $this->jeux->lister('zz test', null, 1)->total);

        $tous = $this->jeux->lister(null, null, 1);
        self::assertCount(20, $tous->jeux);
        self::assertGreaterThanOrEqual(42, $tous->total);
        self::assertSame([], $this->jeux->lister(null, null, 9999)->jeux, 'page hors limites : vide, sans erreur');
    }

    public function testLesCaracteresSpeciauxSontStockesEtRechercesLitteralement(): void
    {
        $editeur = $this->editeurs->creer('ZZ Test Éditeur');
        $piege = "ZZ Test '; DROP TABLE jeu; -- 100% _x_";
        $jeu = $this->jeux->creer($this->saisie($editeur->id, $piege), 'zz-test-piege');

        self::assertSame($piege, $this->jeux->trouver($jeu->id)->titre);
        self::assertGreaterThan(40, (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu'), 'la table jeu existe toujours');
        self::assertSame(1, $this->jeux->lister("'; DROP TABLE jeu; --", null, 1)->total);
        self::assertSame(1, $this->jeux->lister('100% _x_', null, 1)->total);
        self::assertSame(1, $this->jeux->lister('%', null, 1)->total, '« % » est un caractère recherché tel quel, pas un joker qui retournerait tout le catalogue');
    }

    public function testJaquetteDefinirEtRetirer(): void
    {
        $id = $this->jeuDeTest();

        self::assertSame(['slug' => 'zz-test-jeu', 'ancien' => null], $this->jeux->definirJaquette($id, 'jaquettes/'.str_repeat('a', 32).'.webp'));
        self::assertSame('jaquettes/'.str_repeat('a', 32).'.webp', $this->jeux->trouver($id)->jaquette);

        self::assertSame(['slug' => 'zz-test-jeu', 'ancien' => 'jaquettes/'.str_repeat('a', 32).'.webp'], $this->jeux->definirJaquette($id, null));
        self::assertNull($this->jeux->trouver($id)->jaquette);

        $this->expectException(CatalogueException::class);
        $this->jeux->definirJaquette('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', null);
    }

    public function testGenresProposesEnFrancais(): void
    {
        $genres = $this->jeux->genres();

        self::assertNotEmpty($genres);
        self::assertSame(['slug', 'libelle'], array_keys($genres[0]));
    }

    // --- Offres : verrouillage optimiste -----------------------------------------------------------

    public function testCreationDUneOffreEtDoublonActif(): void
    {
        $jeu = $this->jeuDeTest();
        $plateforme = (string) $this->connexion->fetchOne('SELECT id::text FROM plateforme ORDER BY code LIMIT 1');

        ['offre' => $offre, 'jeuSlug' => $slug] = $this->offres->creer($jeu, $plateforme, '14.99', 3);

        self::assertSame('zz-test-jeu', $slug);
        self::assertSame('14.99', $offre->prix);
        self::assertSame(3, $offre->seuilAlerte);
        self::assertTrue($offre->actif);
        self::assertSame(1, $offre->version);
        self::assertSame(0, $offre->stock);

        try {
            $this->offres->creer($jeu, $plateforme, '19.99', 3);
            self::fail('Un 409 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame('error.offre_doublon', $e->cleMessage(), 'une seule offre active par jeu et plateforme (RG-01)');
        }

        foreach ([['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $plateforme], [$jeu, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']] as [$j, $p]) {
            try {
                $this->offres->creer($j, $p, '9.99', 1);
                self::fail('Un 404 était attendu.');
            } catch (CatalogueException $e) {
                self::assertSame(404, $e->codeHttp());
            }
        }
    }

    public function testLeSecondGestionnaireAvecUneVersionPerimeeEstRefuse(): void
    {
        $jeu = $this->jeuDeTest();
        $plateforme = (string) $this->connexion->fetchOne('SELECT id::text FROM plateforme ORDER BY code LIMIT 1');
        $offre = $this->offres->creer($jeu, $plateforme, '10.00', 5)['offre'];

        // Deux gestionnaires chargent l'offre (version 1) ; le premier enregistre.
        $premier = $this->offres->modifier($offre->id, '12.00', 5, true, 1)['offre'];
        self::assertSame(2, $premier->version);
        self::assertSame('12.00', $premier->prix);

        // Le second enregistre avec la version qu'il a chargée : refusé, rien n'est écrasé.
        try {
            $this->offres->modifier($offre->id, '1.00', 99, false, 1);
            self::fail('Un 409 était attendu.');
        } catch (ConflitOffreException $e) {
            self::assertSame(['versionActuelle' => 2], $e->details());
            self::assertSame('error.offre_conflit', $e->cleMessage());
        }
        $actuelle = $this->jeux->trouver($jeu)->offres[0];
        self::assertSame('12.00', $actuelle->prix, 'le travail du premier gestionnaire est intact');
        self::assertSame(5, $actuelle->seuilAlerte);
        self::assertTrue($actuelle->actif);
        self::assertSame(2, $actuelle->version);

        // Après rechargement, la version courante passe.
        self::assertSame(3, $this->offres->modifier($offre->id, '1.00', 99, false, 2)['offre']->version);
    }

    public function testOffreInconnueEtPrixRefuseParLaBase(): void
    {
        try {
            $this->offres->modifier('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', '9.99', 1, true, 1);
            self::fail('Un 404 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame(404, $e->codeHttp());
        }

        $jeu = $this->jeuDeTest();
        $plateforme = (string) $this->connexion->fetchOne('SELECT id::text FROM plateforme ORDER BY code LIMIT 1');
        $offre = $this->offres->creer($jeu, $plateforme, '10.00', 5)['offre'];
        $this->expectException(DriverException::class);
        $this->expectExceptionMessageMatches('/SQLSTATE\[23514\]/');   // violation de contrainte CHECK
        $this->offres->modifier($offre->id, '0', 5, true, 1);   // ck_offre_prix : défense en profondeur si la validation du service était contournée
    }

    public function testReactiverUneOffreQuandUneAutreEstActiveEstRefuse(): void
    {
        $jeu = $this->jeuDeTest();
        $plateforme = (string) $this->connexion->fetchOne('SELECT id::text FROM plateforme ORDER BY code LIMIT 1');
        $ancienne = $this->offres->creer($jeu, $plateforme, '10.00', 5)['offre'];
        $desactivee = $this->offres->modifier($ancienne->id, '10.00', 5, false, 1)['offre'];
        $this->offres->creer($jeu, $plateforme, '12.00', 5);   // une nouvelle offre active remplace l'ancienne

        try {
            $this->offres->modifier($desactivee->id, '10.00', 5, true, $desactivee->version);
            self::fail('Un 409 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame('error.offre_doublon', $e->cleMessage());
        }
    }
}
