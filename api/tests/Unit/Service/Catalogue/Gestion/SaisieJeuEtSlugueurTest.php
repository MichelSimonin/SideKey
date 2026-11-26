<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;
use App\Service\Catalogue\Gestion\SaisieJeu;
use App\Service\Catalogue\Gestion\Slugueur;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaisieJeuEtSlugueurTest extends TestCase
{
    private const EDITEUR = '3f2b8d3e-6c1a-4c55-9d0e-1a2b3c4d5e6f';
    private const DESCRIPTION = 'Une description suffisamment longue pour passer.';

    /**
     * @param array{fr: string, en: string}|null $descriptions
     * @param list<string>                       $genres
     */
    private static function saisie(
        string $titre = 'Aube Écarlate',
        ?string $slug = null,
        ?string $date = '2025-03-14',
        int $pegi = 16,
        string $editeur = self::EDITEUR,
        ?array $descriptions = null,
        array $genres = ['action'],
    ): SaisieJeu {
        return new SaisieJeu($titre, $slug, $date, $pegi, $editeur, true, $descriptions ?? ['fr' => self::DESCRIPTION, 'en' => self::DESCRIPTION], $genres);
    }

    public function testUneSaisieValideEstNormalisee(): void
    {
        $saisie = self::saisie('  Aube Écarlate  ', '  ', genres: ['action', 'action', 'rpg']);

        self::assertSame('Aube Écarlate', $saisie->titre);
        self::assertNull($saisie->slug, 'slug vide = non fourni');
        self::assertSame(['action', 'rpg'], $saisie->genres, 'doublons retirés');
        self::assertSame(strtolower(self::EDITEUR), $saisie->editeurId);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function saisiesInvalides(): iterable
    {
        yield 'titre vide' => [['titre' => '   '], 'titre'];
        yield 'titre trop long' => [['titre' => str_repeat('a', 201)], 'titre'];
        yield 'titre avec balise' => [['titre' => '<script>alert(1)</script>'], 'titre'];
        yield 'titre avec caractère de contrôle' => [['titre' => "Je\x00u"], 'titre'];
        yield 'slug avec majuscule' => [['slug' => 'Mon-Jeu'], 'slug'];
        yield 'slug avec traversée' => [['slug' => '../etc/passwd'], 'slug'];
        yield 'slug trop long' => [['slug' => str_repeat('a', 201)], 'slug'];
        yield 'date au mauvais format' => [['date' => '14/03/2025'], 'dateSortie'];
        yield 'date inexistante' => [['date' => '2025-02-30'], 'dateSortie'];
        yield 'PEGI hors liste' => [['pegi' => 10], 'pegi'];
        yield 'éditeur pas un UUID' => [['editeur' => "1'; DROP TABLE jeu;--"], 'editeurId'];
        yield 'description FR trop courte' => [['descriptions' => ['fr' => 'courte', 'en' => self::DESCRIPTION]], 'descriptions.fr'];
        yield 'description EN trop longue' => [['descriptions' => ['fr' => self::DESCRIPTION, 'en' => str_repeat('x', 4001)]], 'descriptions.en'];
        yield 'description avec HTML' => [['descriptions' => ['fr' => self::DESCRIPTION.' <img src=x onerror=alert(1)>', 'en' => self::DESCRIPTION]], 'descriptions.fr'];
        yield 'description sans anglais' => [['descriptions' => ['fr' => self::DESCRIPTION, 'en' => '']], 'descriptions.en'];
        yield 'genre mal formé' => [['genres' => ['Action!']], 'genres'];
        yield 'trop de genres' => [['genres' => array_map(static fn (int $i): string => 'genre-'.$i, range(1, 11))], 'genres'];
    }

    /** @param array<string, mixed> $surcharge */
    #[DataProvider('saisiesInvalides')]
    public function testUneSaisieInvalideLeveUn422ParChamp(array $surcharge, string $champ): void
    {
        try {
            self::saisie(...$surcharge);
            self::fail('La saisie aurait dû être refusée.');
        } catch (CatalogueException $e) {
            self::assertSame(422, $e->codeHttp());
            self::assertArrayHasKey($champ, $e->erreursChamps());
        }
    }

    public function testLeSlugDepuisUnTitre(): void
    {
        self::assertSame('aube-ecarlate', Slugueur::depuis('Aube Écarlate'));
        self::assertSame('the-witcher-3-wild-hunt', Slugueur::depuis("  The Witcher 3 : Wild Hunt !! "));
        self::assertSame('jeu', Slugueur::depuis('!!!'), 'titre sans lettre ni chiffre');
        self::assertTrue(Slugueur::valide(Slugueur::depuis(str_repeat('mot ', 100))));
        self::assertLessThanOrEqual(Slugueur::MAX, \strlen(Slugueur::depuis(str_repeat('mot ', 100))));
    }

    public function testLaVarianteNumeroteeResteDansLaLimite(): void
    {
        self::assertSame('aube-2', Slugueur::variante('aube', 2));
        $long = str_repeat('a', Slugueur::MAX);
        self::assertSame(Slugueur::MAX, \strlen(Slugueur::variante($long, 12)));
        self::assertTrue(Slugueur::valide(Slugueur::variante($long, 12)));
    }
}
