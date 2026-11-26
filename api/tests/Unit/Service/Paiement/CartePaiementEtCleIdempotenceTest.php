<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Paiement;

use App\Exception\PaiementInvalideException;
use App\Service\Paiement\CartePaiement;
use App\Service\Paiement\CleIdempotence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** US-32 : la carte est contrôlée en format sans jamais être conservée ; la clé d'idempotence est une empreinte. */
final class CartePaiementEtCleIdempotenceTest extends TestCase
{
    private \DateTimeImmutable $maintenant;

    protected function setUp(): void
    {
        $this->maintenant = new \DateTimeImmutable('2026-11-26 12:00:00', new \DateTimeZone('UTC'));
    }

    public function testUneCarteValideNeLaisseSortirQueSesQuatreDerniersChiffres(): void
    {
        $carte = CartePaiement::depuis('4242 4242 4242 4242', '12/30', '123', $this->maintenant);

        self::assertSame('4242424242424242', $carte->numero(), 'espaces retirés, pour le prestataire uniquement');
        self::assertSame('4242', $carte->derniersChiffres());
    }

    /** @return iterable<string, array{string}> */
    public static function numerosDeTest(): iterable
    {
        foreach (['4242424242424242', '4000000000000002', '4000000000009995', '4000000000000119'] as $numero) {
            yield $numero => [$numero];   // les quatre cartes du CDC §11.6 passent la clé de Luhn
        }
        yield 'avec tirets' => ['4242-4242-4242-4242'];
        yield 'Visa 13 chiffres' => ['4222222222222'];
        yield 'American Express' => ['378282246310005'];
    }

    #[DataProvider('numerosDeTest')]
    public function testLesNumerosValidesPassentLaCleDeLuhn(string $numero): void
    {
        self::assertSame(substr(preg_replace('/\D/', '', $numero) ?? '', -4), CartePaiement::depuis($numero, '12/30', '123', $this->maintenant)->derniersChiffres());
    }

    /** @return iterable<string, array{string, string, string, string}> numéro, expiration, cryptogramme, champ en erreur */
    public static function cartesInvalides(): iterable
    {
        yield 'clé de Luhn fausse' => ['4242424242424241', '12/30', '123', 'numeroCarte'];
        yield 'trop court' => ['424242', '12/30', '123', 'numeroCarte'];
        yield 'trop long' => ['42424242424242424242', '12/30', '123', 'numeroCarte'];
        yield 'lettres' => ['4242 4242 4242 42AB', '12/30', '123', 'numeroCarte'];
        yield 'vide' => ['', '12/30', '123', 'numeroCarte'];
        yield 'injection' => ["4242424242424242'; DROP TABLE paiement;--", '12/30', '123', 'numeroCarte'];
        yield 'mois 13' => ['4242424242424242', '13/30', '123', 'expiration'];
        yield 'mois 00' => ['4242424242424242', '00/30', '123', 'expiration'];
        yield 'format libre' => ['4242424242424242', '2030-12', '123', 'expiration'];
        yield 'mois dernier' => ['4242424242424242', '10/26', '123', 'expiration'];
        yield 'année passée' => ['4242424242424242', '12/25', '123', 'expiration'];
        yield 'cryptogramme court' => ['4242424242424242', '12/30', '12', 'cryptogramme'];
        yield 'cryptogramme long' => ['4242424242424242', '12/30', '12345', 'cryptogramme'];
        yield 'cryptogramme lettres' => ['4242424242424242', '12/30', '12a', 'cryptogramme'];
    }

    #[DataProvider('cartesInvalides')]
    public function testUneCarteInvalideEstRefuseeParChamp(string $numero, string $expiration, string $cryptogramme, string $champ): void
    {
        try {
            CartePaiement::depuis($numero, $expiration, $cryptogramme, $this->maintenant);
            self::fail('La carte aurait dû être refusée.');
        } catch (PaiementInvalideException $e) {
            self::assertSame(422, $e->codeHttp());
            self::assertArrayHasKey($champ, $e->erreursChamps());
            if ('' !== $numero) {
                self::assertStringNotContainsString($numero, $e->getMessage().json_encode($e->erreursChamps()), "la valeur saisie n'est jamais renvoyée");
            }
        }
    }

    public function testUneCarteValableJusquAFinDuMoisDExpiration(): void
    {
        // 11/26 : valable toute la journée du 30 novembre 2026
        $finDeMois = new \DateTimeImmutable('2026-11-30 23:59:00', new \DateTimeZone('UTC'));
        self::assertSame('4242', CartePaiement::depuis('4242424242424242', '11/26', '123', $finDeMois)->derniersChiffres());

        $this->expectException(PaiementInvalideException::class);
        CartePaiement::depuis('4242424242424242', '11/26', '123', new \DateTimeImmutable('2026-12-01 00:00:00', new \DateTimeZone('UTC')));
    }

    public function testLaCarteNeSeFuitePasParInadvertance(): void
    {
        $carte = CartePaiement::depuis('4242424242424242', '12/30', '123', $this->maintenant);

        $affichage = print_r($carte, true).var_export($carte->__debugInfo(), true);
        ob_start();
        var_dump($carte);
        $affichage .= (string) ob_get_clean();
        self::assertStringNotContainsString('4242424242424242', $affichage, 'ni var_dump ni print_r ne montrent le numéro');
        self::assertStringNotContainsString('123', str_replace('4242', '', $affichage));
        self::assertStringContainsString('4242', $affichage);

        self::assertSame('{}', json_encode($carte, \JSON_THROW_ON_ERROR), "json_encode n'expose aucun champ privé");
        $this->expectException(\LogicException::class);   // journaux, cache, session : la sérialisation est interdite
        serialize($carte);
    }

    public function testLaCleDIdempotenceEstUneEmpreinteDuClientEtDeSaCle(): void
    {
        $lea = '11111111-2222-4333-8444-555555555555';
        $marcus = '99999999-2222-4333-8444-555555555555';

        $cle = CleIdempotence::depuis('0123456789abcdef0123', $lea);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $cle->empreinte, 'taille de la colonne paiement.cle_idempotence');
        self::assertStringNotContainsString('0123456789abcdef0123', $cle->empreinte, "le texte du client n'est jamais stocké");
        self::assertSame($cle->empreinte, CleIdempotence::depuis('0123456789abcdef0123', $lea)->empreinte, "déterministe : c'est ce qui permet le rejeu");
        self::assertNotSame($cle->empreinte, CleIdempotence::depuis('0123456789abcdef0123', $marcus)->empreinte, 'deux clients, même valeur : aucune collision');
        self::assertNotSame($cle->empreinte, CleIdempotence::depuis('0123456789abcdef0124', $lea)->empreinte);
    }

    /** @return iterable<string, array{?string}> */
    public static function clesInvalides(): iterable
    {
        yield 'absente' => [null];
        yield 'vide' => [''];
        yield 'trop courte' => ['abc123'];
        yield 'trop longue' => [str_repeat('a', 65)];
        yield 'espace' => ['0123456789abcdef 0123'];
        yield 'caractères spéciaux' => ['0123456789abcdef;DROP'];
        yield 'accent' => ['0123456789abcdefé0123'];
        yield 'retour à la ligne' => ["0123456789abcdef0123
X"];
    }

    #[DataProvider('clesInvalides')]
    public function testUneCleDIdempotenceInvalideEstRefusee(?string $brute): void
    {
        try {
            CleIdempotence::depuis($brute, '11111111-2222-4333-8444-555555555555');
            self::fail('La clé aurait dû être refusée.');
        } catch (PaiementInvalideException $e) {
            self::assertArrayHasKey('Idempotency-Key', $e->erreursChamps());
        }
    }
}
