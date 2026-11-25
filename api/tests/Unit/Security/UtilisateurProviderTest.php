<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Utilisateur;
use App\Security\UtilisateurProvider;
use App\Tests\Support\Auth\ComptesEnMemoire;
use App\Tests\Support\Auth\FabriqueCompte;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UtilisateurProviderTest extends TestCase
{
    private ComptesEnMemoire $comptes;
    private UtilisateurProvider $provider;

    protected function setUp(): void
    {
        $this->comptes = new ComptesEnMemoire();
        $this->provider = new UtilisateurProvider($this->comptes);
    }

    public function testChargeLUtilisateurEtSesRolesDepuisLaBase(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1, ['GESTIONNAIRE']));

        $utilisateur = $this->provider->loadUserByIdentifier(FabriqueCompte::uuid(1));

        self::assertInstanceOf(Utilisateur::class, $utilisateur);
        self::assertSame(['ROLE_GESTIONNAIRE'], $utilisateur->getRoles());
        self::assertSame(FabriqueCompte::uuid(1), $utilisateur->getUserIdentifier());
    }

    public function testLesRevendicationsDuJetonSontRattacheesPourLaDeconnexion(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1));

        $utilisateur = $this->provider->loadUserByIdentifierAndPayload(FabriqueCompte::uuid(1), [
            'jti' => 'abc', 'fam' => 'fff', 'exp' => 1_800_000_000, 'roles' => ['ROLE_ADMIN'],
        ]);

        self::assertInstanceOf(Utilisateur::class, $utilisateur);
        self::assertSame(['abc', 'fff', 1_800_000_000], [$utilisateur->jti, $utilisateur->familleId, $utilisateur->expiration]);
        self::assertSame(['ROLE_CLIENT'], $utilisateur->getRoles(), 'les rôles viennent de la base, jamais de la charge du jeton');
    }

    public function testCompteInconnuRefuse(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier(FabriqueCompte::uuid(99));
    }

    public function testCompteDesactiveRefuseImmediatement(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1, actif: false));

        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier(FabriqueCompte::uuid(1));
    }

    public function testIdentifiantForgeRefuse(): void
    {
        $this->expectException(UserNotFoundException::class);
        $this->provider->loadUserByIdentifier("' OR 1=1 --");
    }

    public function testRefreshUserRelitLaBase(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1));
        $utilisateur = $this->provider->loadUserByIdentifier(FabriqueCompte::uuid(1));
        $this->comptes->comptes[FabriqueCompte::uuid(1)] = FabriqueCompte::creer(1, ['CLIENT', 'MODERATEUR']);

        $rafraichi = $this->provider->refreshUser($utilisateur);

        self::assertSame(['ROLE_CLIENT', 'ROLE_MODERATEUR'], $rafraichi->getRoles());
    }

    public function testRefuseUnAutreTypeDUtilisateur(): void
    {
        $this->expectException(UnsupportedUserException::class);
        $this->provider->refreshUser(new InMemoryUser('x', null));
    }

    public function testSupportsClass(): void
    {
        self::assertTrue($this->provider->supportsClass(Utilisateur::class));
        self::assertFalse($this->provider->supportsClass(InMemoryUser::class));
    }
}
