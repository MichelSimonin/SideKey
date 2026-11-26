<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;
use App\Exception\ConflitOffreException;
use App\Exception\JaquetteInvalideException;
use App\Service\Audit\JournalAudit;
use App\Service\Catalogue\Gestion\EditeurAdmin;
use App\Service\Catalogue\Gestion\EditeursAdmin;
use App\Service\Catalogue\Gestion\GestionEditeurs;
use App\Service\Catalogue\Gestion\GestionJaquettes;
use App\Service\Catalogue\Gestion\GestionJeux;
use App\Service\Catalogue\Gestion\GestionOffres;
use App\Service\Catalogue\Gestion\GestionPlateformes;
use App\Service\Catalogue\Gestion\JeuAdmin;
use App\Service\Catalogue\Gestion\JeuxAdmin;
use App\Service\Catalogue\Gestion\OffreAdmin;
use App\Service\Catalogue\Gestion\OffresAdmin;
use App\Service\Catalogue\Gestion\PlateformeAdmin;
use App\Service\Catalogue\Gestion\PlateformesAdmin;
use App\Service\Catalogue\Gestion\SaisieJeu;
use App\Tests\Support\Catalogue\CacheEnMemoire;
use App\Tests\Support\Catalogue\FabriqueWebp;
use App\Tests\Support\Catalogue\StockageJaquettesEnMemoire;
use App\Tests\Support\Cles\JournalAuditEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-09 (US-50, US-51) : les services de gestion revalident, délèguent aux dépôts, tracent dans le journal d'audit et invalident
 * le cache du catalogue. Les dépôts sont des doublures : ici on vérifie les règles et les effets de bord, pas le SQL
 * (couvert par les tests d'intégration).
 */
final class GestionCatalogueTest extends TestCase
{
    private const ACTEUR = '11111111-2222-4333-8444-555555555555';
    private const IP = '203.0.113.9';
    private const ID = '0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
    private const EDITEUR = '3f2b8d3e-6c1a-4c55-9d0e-1a2b3c4d5e6f';

    private CacheEnMemoire $cache;
    private JournalAuditEnMemoire $journal;

    protected function setUp(): void
    {
        $this->cache = new CacheEnMemoire();
        $this->journal = new JournalAuditEnMemoire();
    }

    private static function jeu(string $slug = 'aube-ecarlate', ?string $jaquette = null): JeuAdmin
    {
        return new JeuAdmin(self::ID, $slug, 'Aube Écarlate', '2025-03-14', 16, true, $jaquette, self::EDITEUR, 'Studio', ['fr' => 'x', 'en' => 'y'], [], []);
    }

    private static function saisie(?string $slug = null, string $titre = 'Aube Écarlate'): SaisieJeu
    {
        $d = 'Une description suffisamment longue.';

        return new SaisieJeu($titre, $slug, '2025-03-14', 16, self::EDITEUR, true, ['fr' => $d, 'en' => $d], ['action']);
    }

    private function fichePresente(string $slug): void
    {
        $this->cache->entrees['jeu:'.$slug.':fr'] = ['sentinelle' => true];
    }

    // ---- Jeux -----------------------------------------------------------------------------------------------------

    public function testCreationDUnJeuDeriveLeSlugDuTitreTraceEtInvalideLeCache(): void
    {
        $jeux = $this->createMock(JeuxAdmin::class);
        $jeux->expects(self::once())->method('slugLibre')->with('aube-ecarlate')->willReturn('aube-ecarlate-2');
        $jeux->expects(self::once())->method('creer')->with(self::isInstanceOf(SaisieJeu::class), 'aube-ecarlate-2')->willReturn(self::jeu('aube-ecarlate-2'));
        $this->fichePresente('aube-ecarlate-2');

        $jeu = (new GestionJeux($jeux, $this->cache, $this->journal))->creer(self::saisie(), self::ACTEUR, self::IP);

        self::assertSame('aube-ecarlate-2', $jeu->slug);
        self::assertSame([['action' => JournalAudit::CATALOGUE_CREATION, 'ressource' => 'jeu', 'ressourceId' => self::ID, 'utilisateurId' => self::ACTEUR, 'ip' => self::IP]], $this->journal->entrees);
        self::assertArrayNotHasKey('jeu:aube-ecarlate-2:fr', $this->cache->entrees, 'fiche retirée du cache');
        self::assertSame(1, $this->cache->version, 'listes et filtres invalidés');
    }

    public function testUnSlugSaisiEstUtiliseTelQuelSansRecherche(): void
    {
        $jeux = $this->createMock(JeuxAdmin::class);
        $jeux->expects(self::never())->method('slugLibre');
        $jeux->expects(self::once())->method('creer')->with(self::anything(), 'mon-slug')->willReturn(self::jeu('mon-slug'));

        (new GestionJeux($jeux, $this->cache, $this->journal))->creer(self::saisie('mon-slug'), self::ACTEUR, self::IP);
    }

    public function testModificationInvalideLAncienEtLeNouveauSlug(): void
    {
        $jeux = $this->createStub(JeuxAdmin::class);
        $jeux->method('modifier')->willReturn(['jeu' => self::jeu('nouveau-slug'), 'ancienSlug' => 'ancien-slug']);
        $this->fichePresente('ancien-slug');
        $this->fichePresente('nouveau-slug');

        (new GestionJeux($jeux, $this->cache, $this->journal))->modifier(self::ID, self::saisie('nouveau-slug'), self::ACTEUR, null);

        self::assertArrayNotHasKey('jeu:ancien-slug:fr', $this->cache->entrees, 'l\'ancienne URL ne reste pas en cache');
        self::assertArrayNotHasKey('jeu:nouveau-slug:fr', $this->cache->entrees);
        self::assertSame(JournalAudit::CATALOGUE_MODIFICATION, $this->journal->entrees[0]['action']);
        self::assertNull($this->journal->entrees[0]['ip']);
    }

    public function testDesactivationEstUneSuppressionLogiqueTraceeEtInvalidee(): void
    {
        $jeux = $this->createMock(JeuxAdmin::class);
        $jeux->expects(self::once())->method('desactiver')->willReturn('aube-ecarlate');
        $this->fichePresente('aube-ecarlate');

        (new GestionJeux($jeux, $this->cache, $this->journal))->desactiver(strtoupper(self::ID), self::ACTEUR, self::IP);

        self::assertArrayNotHasKey('jeu:aube-ecarlate:fr', $this->cache->entrees);
        self::assertSame(JournalAudit::CATALOGUE_SUPPRESSION, $this->journal->entrees[0]['action']);
        self::assertSame(self::ID, $this->journal->entrees[0]['ressourceId'], 'identifiant normalisé en minuscules');
    }

    public function testUneEcritureEnEchecNeTraceNiNInvalide(): void
    {
        $jeux = $this->createStub(JeuxAdmin::class);
        $jeux->method('modifier')->willThrowException(CatalogueException::doublon('error.slug_doublon'));
        $this->fichePresente('aube-ecarlate');

        try {
            (new GestionJeux($jeux, $this->cache, $this->journal))->modifier(self::ID, self::saisie('pris'), self::ACTEUR, self::IP);
            self::fail('Un 409 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame(409, $e->codeHttp());
        }
        self::assertSame([], $this->journal->entrees);
        self::assertSame(0, $this->cache->version);
        self::assertArrayHasKey('jeu:aube-ecarlate:fr', $this->cache->entrees);
    }

    // ---- Offres ---------------------------------------------------------------------------------------------------

    /** @return iterable<string, array{string, int, string}> */
    public static function offresInvalides(): iterable
    {
        yield 'prix zéro' => ['0', 5, 'prix'];
        yield 'prix zéro avec décimales' => ['0.00', 5, 'prix'];
        yield 'prix négatif' => ['-5.00', 5, 'prix'];
        yield 'trois décimales' => ['14.999', 5, 'prix'];
        yield 'notation scientifique' => ['1e2', 5, 'prix'];
        yield 'texte' => ['gratuit', 5, 'prix'];
        yield 'virgule' => ['14,99', 5, 'prix'];
        yield 'injection' => ["1; DROP TABLE offre;--", 5, 'prix'];
        yield 'prix énorme' => ['999999', 5, 'prix'];
        yield 'seuil négatif' => ['9.99', -1, 'seuilAlerte'];
        yield 'seuil hors smallint' => ['9.99', 32_768, 'seuilAlerte'];
    }

    #[DataProvider('offresInvalides')]
    public function testUneOffreInvalideEstRefuseeAvantToutAcces(string $prix, int $seuil, string $champ): void
    {
        $offres = $this->createMock(OffresAdmin::class);
        $offres->expects(self::never())->method('creer');
        $offres->expects(self::never())->method('modifier');
        $service = new GestionOffres($offres, $this->cache, $this->journal);

        foreach ([
            static fn () => $service->creer(self::ID, self::EDITEUR, $prix, $seuil, self::ACTEUR, self::IP),
            static fn () => $service->modifier(self::ID, $prix, $seuil, true, 1, self::ACTEUR, self::IP),
        ] as $appel) {
            try {
                $appel();
                self::fail('Un 422 était attendu.');
            } catch (CatalogueException $e) {
                self::assertSame(422, $e->codeHttp());
                self::assertArrayHasKey($champ, $e->erreursChamps());
            }
        }
        self::assertSame([], $this->journal->entrees);
    }

    public function testCreationEtModificationDUneOffreInvalidentLeCache(): void
    {
        $offre = new OffreAdmin(self::ID, self::EDITEUR, 'STEAM', 'Steam', '14.99', 5, true, 2, 10);
        $offres = $this->createStub(OffresAdmin::class);
        $offres->method('creer')->willReturn(['offre' => $offre, 'jeuSlug' => 'aube-ecarlate']);
        $offres->method('modifier')->willReturn(['offre' => $offre, 'jeuSlug' => 'aube-ecarlate']);
        $service = new GestionOffres($offres, $this->cache, $this->journal);

        $this->fichePresente('aube-ecarlate');
        $service->creer(self::ID, self::EDITEUR, '14.99', 5, self::ACTEUR, self::IP);
        self::assertArrayNotHasKey('jeu:aube-ecarlate:fr', $this->cache->entrees);

        $this->fichePresente('aube-ecarlate');
        $service->modifier(self::ID, '9.9', 0, false, 2, self::ACTEUR, self::IP);
        self::assertArrayNotHasKey('jeu:aube-ecarlate:fr', $this->cache->entrees);
        self::assertSame(2, $this->cache->version);
        self::assertSame(['offre', 'offre'], array_column($this->journal->entrees, 'ressource'));
    }

    public function testUneVersionInvalideEstRefusee(): void
    {
        $service = new GestionOffres($this->createStub(OffresAdmin::class), $this->cache, $this->journal);

        $this->expectException(CatalogueException::class);
        $service->modifier(self::ID, '9.99', 5, true, 0, self::ACTEUR, self::IP);
    }

    public function testLeConflitDeVersionNEcritRienEtNInvalidePas(): void
    {
        $offres = $this->createStub(OffresAdmin::class);
        $offres->method('modifier')->willThrowException(new ConflitOffreException(7));
        $this->fichePresente('aube-ecarlate');

        try {
            (new GestionOffres($offres, $this->cache, $this->journal))->modifier(self::ID, '9.99', 5, true, 3, self::ACTEUR, self::IP);
            self::fail('Un 409 était attendu.');
        } catch (ConflitOffreException $e) {
            self::assertSame(409, $e->codeHttp());
            self::assertSame(['versionActuelle' => 7], $e->details());
        }
        self::assertSame([], $this->journal->entrees);
        self::assertSame(0, $this->cache->version);
    }

    // ---- Éditeurs et plateformes ----------------------------------------------------------------------------------

    public function testRenommerUnEditeurInvalideLesFichesDeSesJeux(): void
    {
        $editeurs = $this->createStub(EditeursAdmin::class);
        $editeurs->method('modifier')->willReturn(new EditeurAdmin(self::EDITEUR, 'Nouveau nom', 2));
        $editeurs->method('slugsDesJeux')->willReturn(['jeu-a', 'jeu-b']);
        $this->fichePresente('jeu-a');
        $this->fichePresente('jeu-b');
        $this->fichePresente('autre-jeu');

        (new GestionEditeurs($editeurs, $this->cache, $this->journal))->modifier(self::EDITEUR, '  Nouveau nom ', self::ACTEUR, self::IP);

        self::assertArrayNotHasKey('jeu:jeu-a:fr', $this->cache->entrees);
        self::assertArrayNotHasKey('jeu:jeu-b:fr', $this->cache->entrees);
        self::assertArrayHasKey('jeu:autre-jeu:fr', $this->cache->entrees, 'les fiches sans lien ne sont pas touchées');
        self::assertSame(1, $this->cache->version);
    }

    /** @return iterable<string, array{string}> */
    public static function nomsInvalides(): iterable
    {
        yield 'vide' => [''];
        yield 'espaces' => ['   '];
        yield 'trop long' => [str_repeat('a', 121)];
        yield 'balise' => ['<b>Studio</b>'];
        yield 'contrôle' => ["Studio\x07"];
    }

    #[DataProvider('nomsInvalides')]
    public function testUnNomDEditeurInvalideEstRefuse(string $nom): void
    {
        $editeurs = $this->createMock(EditeursAdmin::class);
        $editeurs->expects(self::never())->method('creer');
        $editeurs->expects(self::never())->method('modifier');
        $service = new GestionEditeurs($editeurs, $this->cache, $this->journal);

        foreach ([static fn () => $service->creer($nom, self::ACTEUR, self::IP), static fn () => $service->modifier(self::EDITEUR, $nom, self::ACTEUR, self::IP)] as $appel) {
            try {
                $appel();
                self::fail('Un 422 était attendu.');
            } catch (CatalogueException $e) {
                self::assertArrayHasKey('nom', $e->erreursChamps());
            }
        }
    }

    public function testSuppressionDEditeurTraceeEtRefuseeSiUtilise(): void
    {
        $editeurs = $this->createStub(EditeursAdmin::class);
        $editeurs->method('supprimer')->willReturnCallback(static function (string $id): void {
            if ('utilise' === $id) {
                throw CatalogueException::utilisee();
            }
        });
        $service = new GestionEditeurs($editeurs, $this->cache, $this->journal);

        $service->supprimer(self::EDITEUR, self::ACTEUR, self::IP);
        self::assertSame(JournalAudit::CATALOGUE_SUPPRESSION, $this->journal->entrees[0]['action']);

        $this->expectException(CatalogueException::class);
        $service->supprimer('utilise', self::ACTEUR, self::IP);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function plateformesInvalides(): iterable
    {
        yield 'code en minuscules' => ['steam', 'Steam', 'code'];
        yield 'code avec tiret' => ['STEAM-DECK', 'Steam Deck', 'code'];
        yield 'code trop long' => ['ABCDEFGHIJKLM', 'Long', 'code'];
        yield 'code vide' => ['', 'Steam', 'code'];
        yield 'libellé vide' => ['STEAM', ' ', 'libelle'];
        yield 'libellé trop long' => ['STEAM', str_repeat('a', 61), 'libelle'];
        yield 'libellé avec balise' => ['STEAM', '<i>Steam</i>', 'libelle'];
    }

    #[DataProvider('plateformesInvalides')]
    public function testUneCreationDePlateformeInvalideEstRefusee(string $code, string $libelle, string $champ): void
    {
        $plateformes = $this->createMock(PlateformesAdmin::class);
        $plateformes->expects(self::never())->method('creer');

        try {
            (new GestionPlateformes($plateformes, $this->cache, $this->journal))->creer($code, $libelle, self::ACTEUR, self::IP);
            self::fail('Un 422 était attendu.');
        } catch (CatalogueException $e) {
            self::assertArrayHasKey($champ, $e->erreursChamps());
        }
    }

    public function testModifierUnePlateformeInvalideLesFichesEtLesFiltres(): void
    {
        $plateformes = $this->createStub(PlateformesAdmin::class);
        $plateformes->method('modifier')->willReturn(new PlateformeAdmin(self::ID, 'STEAM', 'Steam PC', 3));
        $plateformes->method('slugsDesJeux')->willReturn(['jeu-a']);
        $this->fichePresente('jeu-a');

        (new GestionPlateformes($plateformes, $this->cache, $this->journal))->modifier(self::ID, 'Steam PC', self::ACTEUR, self::IP);

        self::assertArrayNotHasKey('jeu:jeu-a:fr', $this->cache->entrees);
        self::assertSame(1, $this->cache->version, 'valeurs des filtres invalidées');
    }

    // ---- Jaquettes ------------------------------------------------------------------------------------------------

    public function testTeleverserUneJaquetteEcritPuisRemplaceEtSupprimeLAncienne(): void
    {
        $stockage = new StockageJaquettesEnMemoire();
        $jeux = $this->createStub(JeuxAdmin::class);
        $jeux->method('definirJaquette')->willReturn(['slug' => 'aube-ecarlate', 'ancien' => 'jaquettes/'.str_repeat('a', 32).'.webp']);
        $jeux->method('trouver')->willReturn(self::jeu());
        $this->fichePresente('aube-ecarlate');

        (new GestionJaquettes($jeux, $stockage, $this->cache, $this->journal))->definir(self::ID, FabriqueWebp::creer(), self::ACTEUR, self::IP);

        self::assertCount(1, $stockage->fichiers, 'le nouveau fichier est conservé');
        self::assertMatchesRegularExpression('/^jaquettes\/[0-9a-f]{32}\.webp$/', array_key_first($stockage->fichiers));
        self::assertSame(['jaquettes/'.str_repeat('a', 32).'.webp'], $stockage->suppressions);
        self::assertArrayNotHasKey('jeu:aube-ecarlate:fr', $this->cache->entrees);
        self::assertSame('jeu_jaquette', $this->journal->entrees[0]['ressource']);
    }

    public function testUneImageInvalideNEcritRienNiNeTouchePasLaBase(): void
    {
        $stockage = new StockageJaquettesEnMemoire();
        $jeux = $this->createMock(JeuxAdmin::class);
        $jeux->expects(self::never())->method('definirJaquette');

        try {
            (new GestionJaquettes($jeux, $stockage, $this->cache, $this->journal))->definir(self::ID, '<?php echo 1;', self::ACTEUR, self::IP);
            self::fail('Un 422 était attendu.');
        } catch (JaquetteInvalideException) {
            // attendu
        }
        self::assertSame([], $stockage->fichiers);
        self::assertSame([], $this->journal->entrees);
    }

    public function testUnIdentifiantForgeNEcritAucunFichier(): void
    {
        $stockage = new StockageJaquettesEnMemoire();

        try {
            (new GestionJaquettes($this->createStub(JeuxAdmin::class), $stockage, $this->cache, $this->journal))->definir('../../x', FabriqueWebp::creer(), self::ACTEUR, self::IP);
            self::fail('Un 404 était attendu.');
        } catch (CatalogueException $e) {
            self::assertSame(404, $e->codeHttp());
        }
        self::assertSame([], $stockage->fichiers);
    }

    public function testSiLaBaseEchoueLeFichierNeufEstRetireEtLAncienConserve(): void
    {
        $stockage = new StockageJaquettesEnMemoire();
        $jeux = $this->createStub(JeuxAdmin::class);
        $jeux->method('definirJaquette')->willThrowException(CatalogueException::introuvable());

        try {
            (new GestionJaquettes($jeux, $stockage, $this->cache, $this->journal))->definir(self::ID, FabriqueWebp::creer(), self::ACTEUR, self::IP);
            self::fail('Un 404 était attendu.');
        } catch (CatalogueException) {
            // attendu
        }
        self::assertSame([], $stockage->fichiers, 'pas de fichier orphelin');
        self::assertCount(1, $stockage->suppressions);
        self::assertSame([], $this->journal->entrees);
    }

    public function testRetirerUneJaquetteSupprimeLeFichier(): void
    {
        $stockage = new StockageJaquettesEnMemoire();
        $ancien = 'jaquettes/'.str_repeat('b', 32).'.webp';
        $stockage->fichiers[$ancien] = 'x';
        $jeux = $this->createMock(JeuxAdmin::class);
        $jeux->expects(self::once())->method('definirJaquette')->with(self::ID, null)->willReturn(['slug' => 'aube-ecarlate', 'ancien' => $ancien]);

        (new GestionJaquettes($jeux, $stockage, $this->cache, $this->journal))->retirer(self::ID, self::ACTEUR, self::IP);

        self::assertSame([], $stockage->fichiers);
        self::assertSame(JournalAudit::CATALOGUE_SUPPRESSION, $this->journal->entrees[0]['action']);
    }
}
