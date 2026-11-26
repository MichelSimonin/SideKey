<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Catalogue\Gestion;

use App\Exception\JaquetteInvalideException;
use App\Service\Catalogue\Gestion\ValidationJaquette;
use App\Tests\Support\Catalogue\FabriqueWebp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Upload sécurisé (F-09) : le type est lu dans le contenu, jamais déduit du nom ni du Content-Type envoyés. */
final class ValidationJaquetteTest extends TestCase
{
    public function testUneImageWebpValideEstAcceptee(): void
    {
        ValidationJaquette::valider(FabriqueWebp::creer(300, 400));
        ValidationJaquette::valider(FabriqueWebp::creer(ValidationJaquette::COTE_MIN, ValidationJaquette::COTE_MAX));

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, string}> */
    public static function refus(): iterable
    {
        $png = "\x89PNG\r\n\x1a\n".str_repeat("\x00", 64);
        $webp = FabriqueWebp::creer();

        yield 'vide' => ['', JaquetteInvalideException::ABSENTE];
        yield 'trop lourde' => [$webp.str_repeat('0', ValidationJaquette::OCTETS_MAX), JaquetteInvalideException::TROP_LOURDE];
        yield 'PNG' => [$png, JaquetteInvalideException::TYPE];
        yield 'JPEG' => ["\xff\xd8\xff\xe0".str_repeat("\x00", 64), JaquetteInvalideException::TYPE];
        yield 'GIF' => ['GIF89a'.str_repeat("\x00", 64), JaquetteInvalideException::TYPE];
        yield 'SVG avec script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', JaquetteInvalideException::TYPE];
        yield 'script PHP' => ['<?php system($_GET["c"]); ?>'.str_repeat(' ', 64), JaquetteInvalideException::TYPE];
        yield 'PHP derrière une signature WebP' => [substr($webp, 0, 12).'<?php system($_GET["c"]); ?>', JaquetteInvalideException::TYPE];
        yield 'RIFF qui n\'est pas du WebP (WAVE)' => ['RIFF'.pack('V', 40).'WAVE'.str_repeat("\x00", 36), JaquetteInvalideException::TYPE];
        yield 'WebP tronqué' => [substr($webp, 0, -4), JaquetteInvalideException::TYPE];
        yield 'WebP suivi de données parasites' => [$webp.'<?php echo 1;', JaquetteInvalideException::TYPE];
        yield 'longueur RIFF mensongère' => ['RIFF'.pack('V', 999_999).substr($webp, 8), JaquetteInvalideException::TYPE];
        yield 'conteneur WebP sans image' => ['RIFF'.pack('V', 36).'WEBP'.str_repeat("\x00", 32), JaquetteInvalideException::TYPE];
        yield 'trop petite (dimensions)' => [FabriqueWebp::creer(10, 10), JaquetteInvalideException::DIMENSIONS];
        yield 'trop grande (bombe de décompression)' => [FabriqueWebp::creer(16_000, 16_000), JaquetteInvalideException::DIMENSIONS];
        yield 'un seul côté hors bornes' => [FabriqueWebp::creer(300, ValidationJaquette::COTE_MAX + 1), JaquetteInvalideException::DIMENSIONS];
    }

    #[DataProvider('refus')]
    public function testUneImageInvalideEstRefusee(string $contenu, string $motif): void
    {
        try {
            ValidationJaquette::valider($contenu);
            self::fail('Le contenu aurait dû être refusé.');
        } catch (JaquetteInvalideException $e) {
            self::assertSame($motif, $e->cleMessage());
            self::assertSame(422, $e->codeHttp());
        }
    }
}
