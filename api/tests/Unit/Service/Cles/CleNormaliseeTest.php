<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Cles;

use App\Service\Cles\CleNormalisee;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CleNormaliseeTest extends TestCase
{
    /** @return iterable<string, array{string, string, string, string}> */
    public static function valides(): iterable
    {
        yield 'format Steam' => ['ABCDE-12345-FGHIJ', 'ABCDE-12345-FGHIJ', 'ABCDE12345FGHIJ', 'GHIJ'];
        yield 'minuscules et espaces de bord' => ["  abcd-efgh-1234\t", 'ABCD-EFGH-1234', 'ABCDEFGH1234', '1234'];
        yield 'sans séparateur' => ['XYZW98765432', 'XYZW98765432', 'XYZW98765432', '5432'];
        yield '8 caractères (minimum)' => ['A1B2C3D4', 'A1B2C3D4', 'A1B2C3D4', 'C3D4'];
        yield '64 caractères (maximum)' => [str_repeat('K', 64), str_repeat('K', 64), str_repeat('K', 64), 'KKKK'];
    }

    #[DataProvider('valides')]
    public function testCleValideNormalisee(string $brute, string $valeur, string $canonique, string $suffixe): void
    {
        $cle = CleNormalisee::depuis($brute);

        self::assertNotNull($cle);
        self::assertSame($valeur, $cle->valeur);
        self::assertSame($canonique, $cle->canonique);
        self::assertSame($suffixe, $cle->suffixe());
    }

    /** @return iterable<string, array{string}> */
    public static function invalides(): iterable
    {
        yield 'vide' => [''];
        yield 'trop courte (7)' => ['ABC-123'];
        yield 'trop longue (65)' => [str_repeat('K', 65)];
        yield 'espace interne' => ['ABCDE 12345'];
        yield 'double tiret' => ['ABCDE--12345'];
        yield 'tiret en tête' => ['-ABCDE12345'];
        yield 'tiret final' => ['ABCDE12345-'];
        yield 'formule de tableur' => ['=HYPERLINK("x")'];
        yield 'guillemets' => ['"ABCDE12345"'];
        yield 'injection SQL' => ["ABCDE'; DROP TABLE cle_licence;--"];
        yield 'caractère de contrôle' => ["ABCDE\x0012345"];
        yield 'lettres accentuées' => ['ÉÈÀÇÙ12345'];
        yield 'balise HTML' => ['<script>alert(1)</script>'];
    }

    #[DataProvider('invalides')]
    public function testCleInvalideRefusee(string $brute): void
    {
        self::assertNull(CleNormalisee::depuis($brute));
    }

    public function testLaValeurNApparaitPasDansUnDump(): void
    {
        $cle = CleNormalisee::depuis('SECRET-VALEUR-1234');
        self::assertNotNull($cle);

        ob_start();
        var_dump($cle);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('SECRET', $dump);
        self::assertStringContainsString('1234', $dump, 'seul le suffixe est visible');
    }
}
