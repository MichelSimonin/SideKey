<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Service\Auth\MotDePasseViolation;
use App\Service\Auth\PolitiqueMotDePasse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Politique du CDC §11.4 : 12 à 128 caractères, tous caractères acceptés, aucune règle de composition. */
final class PolitiqueMotDePasseTest extends TestCase
{
    private PolitiqueMotDePasse $politique;

    protected function setUp(): void
    {
        $this->politique = new PolitiqueMotDePasse();
    }

    /** @return iterable<string, array{string}> */
    public static function motsDePasseConformes(): iterable
    {
        yield 'exactement 12 caractères' => ['xk3p-9mwq-zt'];
        yield 'phrase de passe avec espaces' => ['correct horse battery staple'];
        yield 'accents et emojis (tous caractères acceptés)' => ['Éléphant-été-🔐-2026'];
        yield 'aucune majuscule ni chiffre exigé' => ['uniquementdesminuscules'];
        yield 'exactement 128 caractères' => [str_repeat('aZ', 64)];
        yield '12 caractères multi-octets (compte en caractères, pas en octets)' => ['ééééééééééèà'];
    }

    #[DataProvider('motsDePasseConformes')]
    public function testMotDePasseConforme(string $motDePasse): void
    {
        self::assertSame([], $this->politique->violations($motDePasse), $motDePasse);
    }

    public function testTropCourt(): void
    {
        self::assertSame([MotDePasseViolation::TropCourt], $this->politique->violations('onze-chars!'));   // 11 caractères
        self::assertSame([MotDePasseViolation::TropCourt], $this->politique->violations(''));
    }

    public function testTropLongBornelHachage(): void
    {
        self::assertSame([MotDePasseViolation::TropLong], $this->politique->violations(str_repeat('a', 129)));
        self::assertSame([MotDePasseViolation::TropLong], $this->politique->violations(str_repeat('é', 5000)), 'plafond avant tout hachage');
    }

    /** @return iterable<string, array{string}> */
    public static function motsDePasseTriviaux(): iterable
    {
        yield 'liste des mots courants' => ['password12345'];
        yield 'casse ignorée' => ['PassWord12345'];
        yield 'espaces ignorés' => ['pass word 12345'];
        yield 'un seul caractère répété' => ['aaaaaaaaaaaa'];
        yield 'chiffres répétés' => ['111111111111'];
        yield 'nom du site' => ['SideKey12345'];
    }

    #[DataProvider('motsDePasseTriviaux')]
    public function testMotDePasseCourantRefuse(string $motDePasse): void
    {
        self::assertSame([MotDePasseViolation::Courant], $this->politique->violations($motDePasse));
    }

    public function testRefuseLIdentiteDuTitulaire(): void
    {
        self::assertSame(
            [MotDePasseViolation::ContientIdentifiant],
            $this->politique->violations('xx-camille.dupont-xx', 'camille.dupont@example.com', 'Camille', 'Dupont'),
            'partie locale de l\'e-mail',
        );
        self::assertSame([MotDePasseViolation::ContientIdentifiant], $this->politique->violations('mon-prénom-est-Léandre', '', 'Léandre', ''));
        self::assertSame([MotDePasseViolation::ContientIdentifiant], $this->politique->violations('LE NOM EST DURANDEAU ok', '', '', 'Durandeau'));
    }

    public function testUnPrenomCourtNEstPasUneRaisonDeRefus(): void
    {
        // « Léa » (3 lettres) : refuser tout mot de passe qui la contient serait absurde.
        self::assertSame([], $this->politique->violations('une-phrase-avec-léa-dedans', 'lea@example.com', 'Léa', 'Roy'));
    }

    public function testEstConforme(): void
    {
        self::assertTrue($this->politique->estConforme('une phrase de passe solide'));
        self::assertFalse($this->politique->estConforme('court'));
    }
}
