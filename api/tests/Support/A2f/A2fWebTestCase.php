<?php

declare(strict_types=1);

namespace App\Tests\Support\A2f;

use App\Repository\UtilisateurRepository;
use App\Tests\Support\Auth\AuthWebTestCase;
use App\Tests\Support\Auth\FabriqueCompte;
use OTPHP\TOTP;

/**
 * Base des tests HTTP de l'A2F : API, PostgreSQL et Redis réels, TOTP calculé comme une application d'authentification.
 *
 * Les comptes de test (« a2f-test-… ») ne sont JAMAIS supprimés : le journal d'audit est en ajout seul (trigger), donc une
 * ligne d'audit les référence pour toujours. Leurs courriels sont uniques ; seuls leurs compteurs Redis sont nettoyés.
 */
abstract class A2fWebTestCase extends AuthWebTestCase
{
    /** @var list<string> */
    private array $comptesCrees = [];

    protected function tearDown(): void
    {
        foreach ($this->comptesCrees as $id) {
            $this->redis->del('rl:a2f:'.$id);
            foreach ((array) $this->redis->keys('a2f:used:'.$id.':*') as $cle) {
                $this->redis->del((string) $cle);
            }
        }
        $this->comptesCrees = [];
        parent::tearDown();
    }

    /**
     * Compte du back-office sans A2F configurée (cas de la première connexion).
     *
     * @return array{id: string, email: string}
     */
    protected function creerBackOffice(string $role = 'GESTIONNAIRE'): array
    {
        $email = 'a2f-test-'.strtolower($role).'-'.bin2hex(random_bytes(5)).'@example.test';
        $repository = static::getContainer()->get(UtilisateurRepository::class);
        self::assertInstanceOf(UtilisateurRepository::class, $repository);
        $id = $repository->creerClient($email, FabriqueCompte::hash(self::MOT_DE_PASSE), 'Karim', 'Test', 'fr');
        $this->connexion->executeStatement('DELETE FROM utilisateur_role WHERE utilisateur_id = :id::uuid', ['id' => $id]);
        $this->connexion->executeStatement('INSERT INTO utilisateur_role (utilisateur_id, role_code) VALUES (:id::uuid, :role)', ['id' => $id, 'role' => $role]);
        $this->comptesCrees[] = $id;

        return ['id' => $id, 'email' => $email];
    }

    /**
     * Parcours complet d'une première connexion : mot de passe, enrôlement, premier code. À la fin le compte a l'A2F active.
     *
     * @return array{id: string, email: string, secret: string, secours: list<string>, session: array{statut: int, corps: array<string, mixed>, refresh: ?string, csrf: ?string}}
     */
    protected function backOfficeEnrole(string $role = 'GESTIONNAIRE'): array
    {
        $compte = $this->creerBackOffice($role);
        $connexion = $this->connecter($compte['email']);
        self::assertSame('a2f_enrolement', $connexion['corps']['etape']);
        $jeton = (string) $connexion['corps']['jetonIntermediaire'];

        $this->json('POST', '/api/connexion/a2f/enrolement', jeton: $jeton);
        $secret = (string) $this->etat()['corps']['secret'];

        $this->json('POST', '/api/connexion/a2f/enrolement/confirmation', ['code' => $this->code($secret)], jeton: $jeton);
        $session = $this->etat();
        self::assertSame(201, $session['statut'], (string) $this->client->getResponse()->getContent());

        return $compte + ['secret' => $secret, 'secours' => $session['corps']['codesSecours'], 'session' => $session];
    }

    /** Code TOTP tel que l'afficherait l'application, au pas courant décalé de $decalagePas. */
    protected function code(string $secret, int $decalagePas = 0): string
    {
        return TOTP::createFromSecret($secret)->at(time() + 30 * $decalagePas);
    }

    /** Jeton intermédiaire d'un compte déjà enrôlé (étape « code »). */
    protected function jetonIntermediaire(string $email): string
    {
        $connexion = $this->connecter($email);
        self::assertSame('a2f', $connexion['corps']['etape']);

        return (string) $connexion['corps']['jetonIntermediaire'];
    }
}
