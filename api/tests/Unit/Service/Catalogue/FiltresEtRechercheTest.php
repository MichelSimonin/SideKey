<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Catalogue;

use App\Dto\Request\RechercheCatalogueRequest;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\RechercheTitre;
use App\Service\Catalogue\TriCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Valeurs d'entrée du catalogue : filtres bornés, recherche normalisée, tri en liste blanche, prix sans virgule flottante. */
final class FiltresEtRechercheTest extends TestCase
{
    public function testLesTablesDeCorrespondanceOntLaMemeLongueur(): void
    {
        self::assertSame(mb_strlen(RechercheTitre::ACCENTUEES), mb_strlen(RechercheTitre::SANS_ACCENT));
    }

    /** @return iterable<string, array{string, string}> */
    public static function termes(): iterable
    {
        yield 'majuscule accentuée' => ['ÉCARLATE', 'ecarlate'];
        yield 'minuscule accentuée' => ['écarlate', 'ecarlate'];
        yield 'cédille et tréma' => ['Çaï Aëlys', 'cai aelys'];
        yield 'sans accent' => ['aube', 'aube'];
        yield 'apostrophe conservée' => ["d'Écarlate", "d'ecarlate"];
    }

    #[DataProvider('termes')]
    public function testNormalisationInsensibleALaCasseEtAuxAccents(string $terme, string $attendu): void
    {
        self::assertSame($attendu, RechercheTitre::normaliser($terme));
    }

    /** @return iterable<string, array{string, string}> */
    public static function jokers(): iterable
    {
        yield 'pourcentage' => ['%', '%\%%'];
        yield 'souligné' => ['a_b', '%a\_b%'];
        yield 'antislash' => ['a\b', '%a\\\\b%'];
        yield 'combinaison' => ['%_\\', '%\%\_\\\\%'];
        yield 'terme ordinaire' => ['Aube', '%aube%'];
    }

    #[DataProvider('jokers')]
    public function testLesJokersDuClientSontEchappes(string $terme, string $motif): void
    {
        self::assertSame($motif, RechercheTitre::motifContient($terme), '« % » cherche un pourcentage, pas tout le catalogue');
    }

    public function testTousLesTrisSontDesClausesConstantesAvecUnOrdreTotal(): void
    {
        foreach (TriCatalogue::cases() as $tri) {
            self::assertMatchesRegularExpression('/^b\.[a-z_]+( ASC| DESC)?( NULLS LAST)?(, b\.[a-z_]+( ASC| DESC)?( NULLS LAST)?)*, b\.id ASC$/', $tri->clauseSql(), $tri->name);
        }
        self::assertSame(['titre', 'nouveaute', 'prix_asc', 'prix_desc', 'note'], TriCatalogue::valeurs());
    }

    public function testUnTriInconnuEstImpossible(): void
    {
        self::assertNull(TriCatalogue::tryFrom('titre; DROP TABLE jeu'));
        self::assertNull(TriCatalogue::tryFrom('j.titre'));
        self::assertNull(TriCatalogue::tryFrom('prix_asc DESC'));
        $this->expectException(\ValueError::class);
        TriCatalogue::from('mot_de_passe_hash');
    }

    public function testLesFiltresSontNormalises(): void
    {
        $f = new FiltresCatalogue('  Aube  ', ['STEAM', 'PSN', 'STEAM'], ['rpg', 'action']);

        self::assertSame('Aube', $f->recherche);
        self::assertSame(['PSN', 'STEAM'], $f->plateformes);
        self::assertSame(['action', 'rpg'], $f->genres);
        self::assertNull((new FiltresCatalogue('   '))->recherche);
    }

    public function testLEmpreinteEstStableEtDistinguePageEtCriteres(): void
    {
        $a = new FiltresCatalogue('Aube', ['PSN', 'STEAM']);
        $b = new FiltresCatalogue('aube', ['STEAM', 'PSN']);

        self::assertSame($a->empreinte(), $b->empreinte(), 'casse et ordre ignorés');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->empreinte(), 'seule une empreinte entre dans la clé Redis');
        self::assertNotSame($a->empreinte(), (new FiltresCatalogue('Aube', ['PSN', 'STEAM'], page: 2))->empreinte());
        self::assertNotSame($a->empreinte(), (new FiltresCatalogue('Aube', ['PSN', 'STEAM'], tri: TriCatalogue::Note))->empreinte());
        self::assertNotSame($a->empreinte(), (new FiltresCatalogue('Aube', ['PSN'], pegi: 16))->empreinte());
    }

    /** @return iterable<string, array{\Closure(): FiltresCatalogue}> */
    public static function filtresInvalides(): iterable
    {
        yield 'page 0' => [static fn () => new FiltresCatalogue(page: 0)];
        yield 'page négative' => [static fn () => new FiltresCatalogue(page: -3)];
        yield 'page énorme' => [static fn () => new FiltresCatalogue(page: 10_001)];
        yield 'PEGI inconnu' => [static fn () => new FiltresCatalogue(pegi: 13)];
        yield 'prix négatif' => [static fn () => new FiltresCatalogue(prixMinCentimes: -1)];
        yield 'prix démesuré' => [static fn () => new FiltresCatalogue(prixMaxCentimes: 999_999_999_999)];
        yield 'fourchette inversée' => [static fn () => new FiltresCatalogue(prixMinCentimes: 2000, prixMaxCentimes: 1000)];
        yield 'plateforme minuscule' => [static fn () => new FiltresCatalogue(plateformes: ['steam'])];
        yield 'plateforme injectée' => [static fn () => new FiltresCatalogue(plateformes: ["STEAM') OR 1=1 --"])];
        yield 'genre injecté' => [static fn () => new FiltresCatalogue(genres: ["x' OR '1'='1"])];
        yield 'trop de plateformes' => [static fn () => new FiltresCatalogue(plateformes: array_map(static fn (int $i): string => 'P'.$i, range(1, 11)))];
    }

    /** @param \Closure(): FiltresCatalogue $construire */
    #[DataProvider('filtresInvalides')]
    public function testFiltresHorsBornesRefusesParLeMetier(\Closure $construire): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $construire();
    }

    public function testLaRechercheEstTronqueeALaLimite(): void
    {
        self::assertSame(FiltresCatalogue::RECHERCHE_MAX, mb_strlen((string) (new FiltresCatalogue(str_repeat('é', 500)))->recherche));
    }

    public function testLaPaginationCalculeLeDecalage(): void
    {
        self::assertSame(0, (new FiltresCatalogue())->decalage());
        self::assertSame(20, (new FiltresCatalogue(page: 2))->decalage());
        self::assertSame(199_980, (new FiltresCatalogue(page: 10_000))->decalage());
    }

    /** @return iterable<string, array{string, int}> */
    public static function prix(): iterable
    {
        yield 'entier' => ['30', 3000];
        yield 'un chiffre décimal' => ['12.5', 1250];
        yield 'deux décimales' => ['9.99', 999];
        yield 'zéro' => ['0', 0];
        yield 'sans partie entière non nulle' => ['0.05', 5];
        yield 'grand' => ['999999.99', 99_999_999];
    }

    #[DataProvider('prix')]
    public function testLesPrixSontConvertisEnCentimesSansFlottant(string $saisie, int $centimes): void
    {
        self::assertSame($centimes, (new RechercheCatalogueRequest(prix_max: $saisie))->versFiltres()->prixMaxCentimes);
    }

    public function testLaRequeteSeConvertitEnFiltres(): void
    {
        $f = (new RechercheCatalogueRequest('aube', ['STEAM'], ['action'], '5', '30.50', 16, 'prix_desc', 3))->versFiltres();

        self::assertSame(['aube', ['STEAM'], ['action'], 500, 3050, 16, TriCatalogue::PrixDecroissant, 3],
            [$f->recherche, $f->plateformes, $f->genres, $f->prixMinCentimes, $f->prixMaxCentimes, $f->pegi, $f->tri, $f->page]);
    }
}
