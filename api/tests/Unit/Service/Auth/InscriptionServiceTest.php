<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Exception\EmailDejaUtiliseException;
use App\Exception\MotDePasseInvalideException;
use App\Exception\TropDeTentativesException;
use App\Service\Auth\InscriptionService;
use App\Service\Auth\PolitiqueMotDePasse;
use App\Tests\Support\Auth\ComptesEnMemoire;
use App\Tests\Support\Auth\FabriqueCompte;
use App\Tests\Support\Auth\LimiteurEnMemoire;
use PHPUnit\Framework\TestCase;

final class InscriptionServiceTest extends TestCase
{
    private ComptesEnMemoire $comptes;
    private LimiteurEnMemoire $limiteur;
    private InscriptionService $service;

    protected function setUp(): void
    {
        $this->comptes = new ComptesEnMemoire();
        $this->limiteur = new LimiteurEnMemoire();
        $this->service = new InscriptionService($this->comptes, $this->limiteur, FabriqueCompte::hashers(), new PolitiqueMotDePasse());
    }

    public function testCreeUnCompteClientAvecUnMotDePasseHacheEnArgon2id(): void
    {
        $compte = $this->service->inscrire('  Nouvelle.Cliente@Example.COM ', 'une phrase de passe solide', ' Inès ', 'Roy', 'en', '203.0.113.7');

        self::assertSame('nouvelle.cliente@example.com', $compte->email, 'e-mail normalisé');
        self::assertSame(['CLIENT'], $compte->roles, 'toujours CLIENT');
        self::assertSame('Inès', $compte->prenom);
        self::assertSame('en', $compte->langue);
        self::assertTrue($compte->actif);
        self::assertFalse($compte->emailVerifie, 'l\'e-mail n\'est pas vérifié à la création');
        self::assertFalse($compte->a2fActive);
        self::assertStringStartsWith('$argon2id$', $compte->motDePasseHash);
        self::assertStringNotContainsString('une phrase de passe solide', $compte->motDePasseHash);
    }

    public function testLeSelAleatoireRendLesHashDifferents(): void
    {
        $a = $this->service->inscrire('a@example.com', 'une phrase de passe solide', 'Ana', 'Roy', 'fr', '203.0.113.7');
        $b = $this->service->inscrire('b@example.com', 'une phrase de passe solide', 'Bob', 'Roy', 'fr', '203.0.113.7');

        self::assertNotSame($a->motDePasseHash, $b->motDePasseHash);
    }

    public function testEmailDejaUtiliseInsensibleALaCasse(): void
    {
        $this->service->inscrire('doublon@example.com', 'une phrase de passe solide', 'Ana', 'Roy', 'fr', '203.0.113.7');

        $this->expectException(EmailDejaUtiliseException::class);
        $this->service->inscrire('DOUBLON@example.com', 'une autre phrase de passe', 'Bob', 'Roy', 'fr', '203.0.113.7');
    }

    public function testLeServiceRevalideLaPolitiqueDeMotDePasse(): void
    {
        // Défense en profondeur : même si le DTO était contourné, le métier refuse.
        $this->expectException(MotDePasseInvalideException::class);
        $this->service->inscrire('court@example.com', 'trop court', 'Ana', 'Roy', 'fr', '203.0.113.7');
    }

    public function testRefuseUnMotDePasseContenantLEmail(): void
    {
        $this->expectException(MotDePasseInvalideException::class);
        $this->service->inscrire('marguerite.durand@example.com', 'marguerite.durand-2026', 'Ana', 'Roy', 'fr', '203.0.113.7');
    }

    public function testLangueInconnueRetombeSurLeFrancais(): void
    {
        $compte = $this->service->inscrire('lang@example.com', 'une phrase de passe solide', 'Ana', 'Roy', 'de', '203.0.113.7');

        self::assertSame('fr', $compte->langue);
    }

    public function testLimitationDesCreationsParAdresse(): void
    {
        for ($i = 1; $i <= InscriptionService::TENTATIVES_MAX; ++$i) {
            $this->service->inscrire("c$i@example.com", 'une phrase de passe solide', 'Ana', 'Roy', 'fr', '203.0.113.7');
        }

        $this->expectException(TropDeTentativesException::class);
        $this->service->inscrire('de-trop@example.com', 'une phrase de passe solide', 'Ana', 'Roy', 'fr', '203.0.113.7');
    }

    public function testLeCompteNEstPasCreeQuandLaLimiteEstAtteinte(): void
    {
        $this->limiteur->compteurs['rl:register:203.0.113.7'] = InscriptionService::TENTATIVES_MAX;

        try {
            $this->service->inscrire('bloque@example.com', 'une phrase de passe solide', 'Ana', 'Roy', 'fr', '203.0.113.7');
            self::fail('Limitation attendue.');
        } catch (TropDeTentativesException) {
        }

        self::assertNull($this->comptes->parEmail('bloque@example.com'));
    }
}
