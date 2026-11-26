<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Cles;

use App\Exception\ImportClesInvalideException;
use App\Exception\OffreIndisponibleException;
use App\Service\Audit\JournalAudit;
use App\Service\Cles\ImportClesService;
use App\Service\Cles\RapportImport;
use App\Tests\Support\Catalogue\CacheEnMemoire;
use App\Tests\Support\Cles\ClesLicenceEnMemoire;
use App\Tests\Support\Cles\JournalAuditEnMemoire;
use App\Tests\Support\Cles\SecretsFactices;
use PHPUnit\Framework\TestCase;

/** US-52 : import CSV, doublons rejetés ligne à ligne, rapport, lots de 500, 1 000 lignes au plus. */
final class ImportClesServiceTest extends TestCase
{
    private const OFFRE = '0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
    private const GESTIONNAIRE = '11111111-2222-4333-8444-555555555555';

    private ClesLicenceEnMemoire $cles;
    private JournalAuditEnMemoire $journal;
    private CacheEnMemoire $cache;
    private ImportClesService $service;

    protected function setUp(): void
    {
        $this->cles = new ClesLicenceEnMemoire();
        $this->cles->offres[self::OFFRE] = 'hollow-knight';
        $this->journal = new JournalAuditEnMemoire();
        $this->cache = new CacheEnMemoire();
        $this->service = new ImportClesService($this->cles, SecretsFactices::chiffreur(), $this->journal, $this->cache);
    }

    public function testRapportAvecAccepteesDoublonsEtErreurs(): void
    {
        $csv = implode("\n", [
            'cle',                    // 1 en-tête ignorée
            'AAAAA-11111-BBBBB',      // 2 acceptée
            'aaaaa11111bbbbb',        // 3 même clé autrement écrite : doublon du fichier
            '',                       // 4 vide ignorée
            'trop court',             // 5 format
            'CCCCC-22222-DDDDD',      // 6 acceptée
            '=CMD()',                 // 7 format (formule)
        ]);

        $rapport = $this->importer($csv);

        self::assertSame('TERMINE', $rapport->statut());
        self::assertSame(5, $rapport->nbLignes(), 'en-tête et lignes vides ne comptent pas');
        self::assertSame(2, $rapport->nbAcceptees());
        self::assertSame(1, $rapport->nbDoublons());
        self::assertSame(2, $rapport->nbErreurs());
        self::assertSame([
            ['ligne' => 3, 'motif' => RapportImport::MOTIF_DOUBLON_FICHIER],
            ['ligne' => 5, 'motif' => RapportImport::MOTIF_FORMAT],
            ['ligne' => 7, 'motif' => RapportImport::MOTIF_FORMAT],
        ], $rapport->refus());
    }

    public function testUneCleDejaEnStockEstUnDoublon(): void
    {
        $this->importer("AAAAA-11111-BBBBB\n");

        $rapport = $this->importer("AAAAA-11111-BBBBB\nEEEEE-33333-FFFFF\n");

        self::assertSame(1, $rapport->nbAcceptees());
        self::assertSame([['ligne' => 1, 'motif' => RapportImport::MOTIF_DOUBLON_STOCK]], $rapport->refus());
    }

    public function testLeStockNeRecoitQueDesChiffres(): void
    {
        $this->importer("AAAAA-11111-BBBBB\n");

        $stockee = array_values($this->cles->stock)[0];
        self::assertStringNotContainsString('AAAAA', $stockee->chiffre);
        self::assertSame('BBBB', $stockee->suffixe);
        self::assertSame('AAAAA-11111-BBBBB', SecretsFactices::chiffreur()->dechiffrer($stockee->chiffre, self::OFFRE));
    }

    public function testCsvExcelPointVirguleBomEtFinsDeLigneWindows(): void
    {
        $csv = "\xEF\xBB\xBFkey;commentaire\r\nAAAAA-11111-BBBBB;lot A\r\n\"CCCCC-22222-DDDDD\";lot B\r\n";

        $rapport = $this->importer($csv);

        self::assertSame(2, $rapport->nbAcceptees());
        self::assertSame([], $rapport->refus());
    }

    public function testLotsDe500Lignes(): void
    {
        $rapport = $this->importer(self::fichier(1000));

        self::assertSame(1000, $rapport->nbAcceptees());
        self::assertSame([500, 500], $this->cles->lots);
    }

    public function testPlusDe1000ClesRefuseEnBlocSansRienInserer(): void
    {
        try {
            $this->importer(self::fichier(1001));
            self::fail('exception attendue');
        } catch (ImportClesInvalideException $e) {
            self::assertSame('error.import_trop_de_lignes', $e->cleMessage());
            self::assertSame(422, $e->codeHttp());
        }
        self::assertSame([], $this->cles->lots);
        self::assertSame([], $this->cles->imports, 'aucun rapport ouvert pour un fichier refusé');
    }

    public function testFichierVide(): void
    {
        $this->expectExceptionObject(new ImportClesInvalideException('error.import_vide'));
        $this->importer("cle\n\n  \n");
    }

    public function testFichierQuiNEstPasEnUtf8(): void
    {
        $this->expectExceptionObject(new ImportClesInvalideException('error.import_encodage'));
        $this->importer("AAAAA-11111-BBBBB\n\xE9\xE8\n");
    }

    public function testOffreInconnueOuRetireeDeLaVente(): void
    {
        $this->expectException(OffreIndisponibleException::class);
        $this->service->importer('9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a', 'cles.csv', "AAAAA-11111-BBBBB\n", self::GESTIONNAIRE, null);
    }

    public function testImportJournaliseEtCacheDuCatalogueInvalide(): void
    {
        $this->cache->entrees['jeu:hollow-knight:fr'] = ['en cache'];
        $version = $this->cache->version;

        $rapport = $this->importer("AAAAA-11111-BBBBB\n");

        self::assertSame([[
            'action' => JournalAudit::IMPORT_CLES, 'ressource' => 'import_cles', 'ressourceId' => $rapport->id,
            'utilisateurId' => self::GESTIONNAIRE, 'ip' => '203.0.113.9',
        ]], $this->journal->entrees);
        self::assertArrayNotHasKey('jeu:hollow-knight:fr', $this->cache->entrees, 'le stock de la fiche a changé');
        self::assertSame($version + 1, $this->cache->version, 'les listes (disponibilité) aussi');
    }

    public function testSansCleAccepteeLeCacheNEstPasTouche(): void
    {
        $this->cache->entrees['jeu:hollow-knight:fr'] = ['en cache'];

        $this->importer("format invalide\n");

        self::assertArrayHasKey('jeu:hollow-knight:fr', $this->cache->entrees);
    }

    public function testPanneEnCoursDImportLeRapportEstClosEnEchec(): void
    {
        $this->cles->lotEnPanne = 2;
        try {
            $this->importer(self::fichier(800));
            self::fail('exception attendue');
        } catch (\RuntimeException) {
        }

        $rapport = array_values($this->cles->imports)[0];
        self::assertSame('ECHEC', $rapport->statut());
        self::assertSame(500, $rapport->nbAcceptees(), 'le premier lot reste en base et le rapport le dit');
    }

    public function testNomDeFichierNettoye(): void
    {
        $rapport = $this->service->importer(self::OFFRE, "../../etc/pass\x00wd.csv", "AAAAA-11111-BBBBB\n", self::GESTIONNAIRE, null);

        self::assertSame('passwd.csv', $rapport->nomFichier);
    }

    public function testLeRapportNeContientAucuneValeurDeCle(): void
    {
        $rapport = $this->importer("AAAAA-11111-BBBBB\naaaaa11111bbbbb\nMAUVAISE CLE\n");

        $json = (string) json_encode([$rapport->refus(), $rapport->nomFichier, $rapport->statut()]);
        self::assertStringNotContainsString('AAAAA', $json);
        self::assertStringNotContainsString('MAUVAISE', $json);
    }

    private function importer(string $csv): RapportImport
    {
        return $this->service->importer(self::OFFRE, 'cles.csv', $csv, self::GESTIONNAIRE, '203.0.113.9');
    }

    private static function fichier(int $lignes): string
    {
        $cles = [];
        for ($i = 1; $i <= $lignes; ++$i) {
            $cles[] = sprintf('LOT%05d-%s', $i, strtoupper(bin2hex(random_bytes(6))));
        }

        return implode("\n", $cles);
    }
}
