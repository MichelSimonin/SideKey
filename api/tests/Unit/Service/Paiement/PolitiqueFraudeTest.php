<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Paiement;

use App\Service\Paiement\ConfigurationFraude;
use App\Service\Paiement\ContextePaiement;
use App\Service\Paiement\PolitiqueFraude;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RG-14, niveau « Équilibré » (CDC §6.3) : chaque seuil est testé À LA FRONTIÈRE, là où se cachent les erreurs de comparaison
 * (≥ au lieu de >), et la configuration refuse toute valeur qui désactiverait une protection.
 */
final class PolitiqueFraudeTest extends TestCase
{
    private const MAINTENANT = '2026-11-26 12:00:00';

    private PolitiqueFraude $politique;

    protected function setUp(): void
    {
        $this->politique = new PolitiqueFraude(new ConfigurationFraude(10, 3, 3600, 24, 150, 200));
    }

    private static function contexte(int $centimes, string $compteCreeLe, bool $premiere): ContextePaiement
    {
        $maintenant = new \DateTimeImmutable(self::MAINTENANT);

        return new ContextePaiement('id', 'ref', 'EN_ATTENTE_PAIEMENT', $centimes, 1, $maintenant->modify('+10 minutes'), new \DateTimeImmutable($compteCreeLe), $premiere);
    }

    /** @return iterable<string, array{int, string, bool, bool}> montant en centimes, création du compte, première commande, à vérifier ? */
    public static function cas(): iterable
    {
        // Compte de moins de 24 h : « à vérifier » au-delà de 150,00 €
        yield 'compte récent, 149,99 €' => [14_999, '2026-11-26 11:00:00', false, false];
        yield 'compte récent, 150,00 € pile' => [15_000, '2026-11-26 11:00:00', false, false];
        yield 'compte récent, 150,01 €' => [15_001, '2026-11-26 11:00:00', false, true];
        yield 'compte récent, très gros panier' => [99_999, '2026-11-26 11:59:59', false, true];
        // Frontière d'âge : 24 h pile n'est plus « moins de 24 h »
        yield 'compte de 23 h 59, 151 €' => [15_100, '2026-11-25 12:00:01', false, true];
        yield 'compte de 24 h pile, 151 €' => [15_100, '2026-11-25 12:00:00', false, false];
        yield 'compte de 25 h, 151 €' => [15_100, '2026-11-25 11:00:00', false, false];
        // Première commande : « à vérifier » au-delà de 200,00 €
        yield '1re commande, 199,99 €' => [19_999, '2025-01-01 00:00:00', true, false];
        yield '1re commande, 200,00 € pile' => [20_000, '2025-01-01 00:00:00', true, false];
        yield '1re commande, 200,01 €' => [20_001, '2025-01-01 00:00:00', true, true];
        yield 'commande suivante, 500 €, compte ancien' => [50_000, '2025-01-01 00:00:00', false, false];
        // Les deux règles sont indépendantes (OU)
        yield 'compte récent ET 1re commande, 151 €' => [15_100, '2026-11-26 09:00:00', true, true];
        yield 'compte récent ET 1re commande, 100 €' => [10_000, '2026-11-26 09:00:00', true, false];
        yield 'compte ancien, 1re commande, 160 €' => [16_000, '2025-01-01 00:00:00', true, false];
    }

    #[DataProvider('cas')]
    public function testLesSeuilsDeVerificationSontStrictsAuxFrontieres(int $centimes, string $compteCreeLe, bool $premiere, bool $attendu): void
    {
        $contexte = self::contexte($centimes, $compteCreeLe, $premiere);

        self::assertSame($attendu, $this->politique->commandeSuspecte($contexte, new \DateTimeImmutable(self::MAINTENANT)));
    }

    /** @return iterable<string, array{int, int, bool}> déjà achetées, nouvelles, plafond atteint ? */
    public static function quotas(): iterable
    {
        yield 'aucune clé, 5 demandées' => [0, 5, false];
        yield '9 + 1 = 10 : pile au plafond, autorisé' => [9, 1, false];
        yield '5 + 5 = 10 : autorisé' => [5, 5, false];
        yield '6 + 5 = 11 : refusé' => [6, 5, true];
        yield '10 + 1 : refusé' => [10, 1, true];
        yield 'beaucoup de clés d\'un coup' => [0, 11, true];
    }

    #[DataProvider('quotas')]
    public function testLePlafondDe10ClesSur24hEstStrict(int $deja, int $nouvelles, bool $atteint): void
    {
        self::assertSame($atteint, $this->politique->limiteClesAtteinte($deja, $nouvelles));
        self::assertSame(10, $this->politique->plafondCles());
    }

    public function testLesSeuilsSontAjustablesSansToucherAuCode(): void
    {
        $strict = new PolitiqueFraude(new ConfigurationFraude(5, 3, 3600, 24, 50, 100));
        $maintenant = new \DateTimeImmutable(self::MAINTENANT);

        self::assertTrue($strict->commandeSuspecte(self::contexte(5_001, '2026-11-26 11:00:00', false), $maintenant));
        self::assertTrue($strict->limiteClesAtteinte(1, 5));
        self::assertFalse($this->politique->limiteClesAtteinte(1, 5));
    }

    /** @return iterable<string, array{array<int, int>}> */
    public static function configurationsDangereuses(): iterable
    {
        yield 'plafond de clés à 0 : tout refuser' => [[0, 3, 3600, 24, 150, 200]];
        yield 'plafond de clés négatif' => [[-1, 3, 3600, 24, 150, 200]];
        yield 'plafond de clés absurde' => [[100_000, 3, 3600, 24, 150, 200]];
        yield 'échecs à 0 : blocage dès le premier paiement' => [[10, 0, 3600, 24, 150, 200]];
        yield 'blocage de 0 s : ne bloque rien' => [[10, 3, 0, 24, 150, 200]];
        yield 'blocage de 10 s : dérisoire' => [[10, 3, 10, 24, 150, 200]];
        yield 'ancienneté de 0 h : aucun compte récent' => [[10, 3, 3600, 0, 150, 200]];
        yield 'seuil de compte récent à 0 € : tout vérifier' => [[10, 3, 3600, 24, 0, 200]];
        yield 'seuil de 1re commande à 0 €' => [[10, 3, 3600, 24, 150, 0]];
        yield 'seuil de 1re commande à 1 M€ : jamais déclenché' => [[10, 3, 3600, 24, 150, 1_000_000]];
    }

    /** @param array<int, int> $valeurs */
    #[DataProvider('configurationsDangereuses')]
    public function testUneConfigurationQuiDesactiveUneProtectionEstRefusee(array $valeurs): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration anti-fraude invalide');

        new ConfigurationFraude(...$valeurs);
    }

    public function testLesBornesSontAtteignables(): void
    {
        self::assertSame(1, (new ConfigurationFraude(1, 1, 60, 1, 1, 1))->clesMax24h);
        self::assertSame(1_000, (new ConfigurationFraude(1_000, 100, 7 * 86_400, 720, 100_000, 100_000))->clesMax24h);
    }
}
