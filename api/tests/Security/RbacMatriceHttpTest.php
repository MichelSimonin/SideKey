<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Permission;
use App\Tests\Support\Auth\AuthWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Matrice RBAC du CDC §11.5 prouvée de bout en bout par HTTP : pare-feu JWT, voter, gestionnaire d'erreurs.
 * « Accès refusé = 403 » pour un utilisateur connecté sans le droit, « 401 » pour un visiteur.
 * Les comptes sont ceux du jeu d'essai (un par rôle).
 */
#[Group('database')]
#[Group('redis')]
final class RbacMatriceHttpTest extends AuthWebTestCase
{
    /** Comptes du jeu d'essai : un par rôle. */
    private const COMPTES = [
        'CLIENT' => 'client01@sidekey.test',
        'GESTIONNAIRE' => 'gestionnaire@sidekey.test',
        'MODERATEUR' => 'moderateur@sidekey.test',
        'ADMIN' => 'admin@sidekey.test',   // A2F active : jeton émis directement, comme après la 2e étape
    ];

    /** Cases cochées du CDC §11.5. */
    private const AUTORISE = [
        'CLIENT' => ['commander', 'deposer_avis'],
        'GESTIONNAIRE' => ['gerer_catalogue', 'consulter_commandes'],
        'MODERATEUR' => ['moderer'],
        'ADMIN' => ['gerer_catalogue', 'moderer', 'consulter_commandes', 'valider_commande_suspecte', 'gerer_roles_et_audit'],
    ];

    protected static function adresseIp(): string
    {
        return '198.51.100.30';
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function matrice(): iterable
    {
        foreach (self::AUTORISE as $role => $accordees) {
            foreach (Permission::cases() as $permission) {
                $attendu = \in_array($permission->nom(), $accordees, true) ? 200 : 403;
                yield "$role → {$permission->nom()} = $attendu" => [$role, $permission->nom(), $attendu];
            }
        }
    }

    #[DataProvider('matrice')]
    public function testMatriceRbacParHttp(string $role, string $permission, int $attendu): void
    {
        $this->json('GET', '/api/_sonde/'.$permission, jeton: $this->jetonPour(self::COMPTES[$role]));

        self::assertSame($attendu, $this->etat()['statut'], "$role sur $permission");
        if (403 === $attendu) {
            self::assertSame(['message' => 'Accès refusé.'], $this->etat()['corps'], 'refus générique : aucune indication sur la règle');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function permissions(): iterable
    {
        foreach (Permission::cases() as $permission) {
            yield $permission->nom() => [$permission->nom()];
        }
    }

    #[DataProvider('permissions')]
    public function testUnVisiteurSansJetonRecoit401(string $permission): void
    {
        $this->json('GET', '/api/_sonde/'.$permission);

        self::assertSame(401, $this->etat()['statut'], '401 (non authentifié) et non 403 : on ne dit pas que la route existe');
    }

    public function testLeRefusEstGeneriqueEtSansCache(): void
    {
        $this->json('GET', '/api/_sonde/gerer_roles_et_audit', jeton: $this->jetonPour(self::COMPTES['CLIENT']));

        self::assertSame(403, $this->etat()['statut']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsStringIgnoringCase('ADMIN', (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsStringIgnoringCase('role', (string) $this->client->getResponse()->getContent());
    }

    public function testLeRefusEstTraduit(): void
    {
        $this->json('GET', '/api/_sonde/gerer_roles_et_audit', jeton: $this->jetonPour(self::COMPTES['CLIENT']), serveur: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertSame(['message' => 'Access denied.'], $this->etat()['corps']);
    }

    public function testUnePermissionInconnueEstRefusee(): void
    {
        $this->json('GET', '/api/_sonde/super_pouvoir', jeton: $this->jetonPour(self::COMPTES['ADMIN']));

        self::assertSame(403, $this->etat()['statut'], 'refus par défaut, même pour un administrateur');
    }

    public function testToutesLesRoutesNonPubliquesExigentUneAuthentification(): void
    {
        // Refus par défaut (access_control) : une route /api/* qui n'est pas dans la liste publique est protégée.
        foreach (['/api/moi', '/api/deconnexion', '/api/nouvelle-route-non-declaree'] as $uri) {
            $this->json('GET', $uri);
            self::assertContains($this->etat()['statut'], [401, 404, 405], $uri);
            self::assertNotSame(200, $this->etat()['statut'], $uri);
        }
    }

    public function testLesPermissionsExposeesParMoiCorrespondentALaMatrice(): void
    {
        foreach (self::COMPTES as $role => $email) {
            $this->json('GET', '/api/moi', jeton: $this->jetonPour($email));

            self::assertSame(self::AUTORISE[$role], $this->etat()['corps']['utilisateur']['permissions'], $role);
        }
    }

    public function testUnRoleRetireEstSansEffetAuProchainAppel(): void
    {
        // Les rôles sont relus en base à chaque requête : retirer un rôle prend effet immédiatement,
        // sans attendre l'expiration du jeton d'accès (15 min).
        $client = $this->creerClient();
        $jeton = $this->connecter($client['email'])['corps']['jetonAcces'];
        $this->json('GET', '/api/_sonde/commander', jeton: $jeton);
        self::assertSame(200, $this->etat()['statut']);

        $this->connexion->executeStatement('DELETE FROM utilisateur_role WHERE utilisateur_id = :id::uuid', ['id' => $client['id']]);

        $this->json('GET', '/api/_sonde/commander', jeton: $jeton);
        self::assertSame(403, $this->etat()['statut']);
    }
}
