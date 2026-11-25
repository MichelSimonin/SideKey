<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Permission;
use App\Security\Utilisateur;
use App\Security\Voter\RbacVoter;
use App\Tests\Support\Auth\FabriqueCompte;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * La matrice RBAC du CDC §11.5, vérifiée case par case : 4 rôles × 7 permissions = 28 décisions.
 * Seules les cases cochées au CDC donnent accès ; tout le reste est un refus.
 */
final class RbacVoterTest extends TestCase
{
    /** Cases cochées du CDC §11.5 (hors « Consulter le catalogue », public). */
    private const AUTORISE = [
        'CLIENT' => [Permission::Commander, Permission::DeposerAvis],
        'GESTIONNAIRE' => [Permission::GererCatalogue, Permission::ConsulterCommandes],
        'MODERATEUR' => [Permission::Moderer],
        'ADMIN' => [Permission::GererCatalogue, Permission::Moderer, Permission::ConsulterCommandes,
            Permission::ValiderCommandeSuspecte, Permission::GererRolesEtAudit],
    ];

    /** @return iterable<string, array{string, Permission, bool}> */
    public static function matrice(): iterable
    {
        foreach (self::AUTORISE as $role => $permissionsAccordees) {
            foreach (Permission::cases() as $permission) {
                $attendu = \in_array($permission, $permissionsAccordees, true);
                yield \sprintf('%s %s %s', $role, $attendu ? 'peut' : 'ne peut pas', $permission->nom()) => [$role, $permission, $attendu];
            }
        }
    }

    #[DataProvider('matrice')]
    public function testMatriceRbac(string $role, Permission $permission, bool $attendu): void
    {
        $vote = (new RbacVoter())->vote($this->jeton([$role]), null, [$permission->value]);

        self::assertSame($attendu ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED, $vote);
    }

    public function testLAdminNeCommandePas(): void
    {
        // Séparation des devoirs : pas de hiérarchie de rôles, l'ADMIN n'hérite pas de CLIENT.
        self::assertSame(VoterInterface::ACCESS_DENIED, (new RbacVoter())->vote($this->jeton(['ADMIN']), null, [Permission::Commander->value]));
    }

    public function testLeModerateurNAccedeNiAuxCommandesNiAuxCles(): void
    {
        $voter = new RbacVoter();
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(['MODERATEUR']), null, [Permission::ConsulterCommandes->value]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(['MODERATEUR']), null, [Permission::Commander->value]));
    }

    public function testUnCompteMultiRoleCumuleLesDroits(): void
    {
        $voter = new RbacVoter();
        $jeton = $this->jeton(['CLIENT', 'MODERATEUR']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($jeton, null, [Permission::Commander->value]));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($jeton, null, [Permission::Moderer->value]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($jeton, null, [Permission::GererRolesEtAudit->value]));
    }

    public function testUnCompteSansRoleNAAucunDroit(): void
    {
        foreach (Permission::cases() as $permission) {
            self::assertSame(VoterInterface::ACCESS_DENIED, (new RbacVoter())->vote($this->jeton([]), null, [$permission->value]), $permission->nom());
        }
    }

    public function testUnVisiteurAnonymeEstRefuse(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, (new RbacVoter())->vote(new NullToken(), null, [Permission::Commander->value]));
    }

    public function testUnRoleInconnuOuForgeNeDonneRien(): void
    {
        $voter = new RbacVoter();
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->jeton(['SUPER_ADMIN', 'ROLE_ADMIN', 'admin']), null, [Permission::GererRolesEtAudit->value]));
    }

    public function testNeSePrononcePasSurUnAttributInconnu(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new RbacVoter())->vote($this->jeton(['ADMIN']), null, ['PERMISSION_INEXISTANTE']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new RbacVoter())->vote($this->jeton(['ADMIN']), null, ['ROLE_ADMIN']));
    }

    public function testChaquePermissionAAuMoinsUnRoleEtAucunRoleInconnu(): void
    {
        $rolesConnus = ['CLIENT', 'GESTIONNAIRE', 'MODERATEUR', 'ADMIN'];   // ck_role_code, schéma T-01
        foreach (Permission::cases() as $permission) {
            self::assertNotEmpty($permission->rolesAutorises(), $permission->name);
            self::assertSame([], array_diff($permission->rolesAutorises(), $rolesConnus), $permission->name);
        }
    }

    public function testLesRolesSymfonyViennentDeLaTableRole(): void
    {
        $utilisateur = new Utilisateur(FabriqueCompte::creer(1, ['CLIENT', 'ADMIN']));

        self::assertSame(['ROLE_CLIENT', 'ROLE_ADMIN'], $utilisateur->getRoles());
        self::assertNotContains('ROLE_USER', $utilisateur->getRoles(), 'aucun droit implicite');
    }

    /** @param list<string> $roles */
    private function jeton(array $roles): UsernamePasswordToken
    {
        $utilisateur = new Utilisateur(FabriqueCompte::creer(1, $roles));

        return new UsernamePasswordToken($utilisateur, 'api', $utilisateur->getRoles());
    }
}
