<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Panier;

use App\Dto\Response\PanierResponse;
use App\Service\Panier\PanierId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PanierIdTest extends TestCase
{
    public function testClesEtDureesDeVie(): void
    {
        $visiteur = PanierId::visiteur('AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA');
        $client = PanierId::client('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');

        self::assertSame('cart:anon:aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $visiteur->cle);
        self::assertSame(86_400, $visiteur->ttl);
        self::assertSame('cart:bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $client->cle);
        self::assertSame(604_800, $client->ttl);
    }

    /** @return iterable<string, array{string}> */
    public static function valeursHostiles(): iterable
    {
        yield 'vide' => [''];
        yield 'joker Redis' => ['*'];
        yield 'injection de clé' => ['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa:other'];
        yield 'pas un uuid' => ['panier-de-quelqu-un'];
    }

    #[DataProvider('valeursHostiles')]
    public function testRefuseUnIdentifiantInvalide(string $valeur): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PanierId::visiteur($valeur);
    }

    public function testMontantsEnChainesDecimales(): void
    {
        self::assertSame('0.00', PanierResponse::montant(0));
        self::assertSame('0.05', PanierResponse::montant(5));
        self::assertSame('29.99', PanierResponse::montant(2999));
        self::assertSame('1234.50', PanierResponse::montant(123450));
    }
}
