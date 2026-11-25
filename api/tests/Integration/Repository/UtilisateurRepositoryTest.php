<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Exception\EmailDejaUtiliseException;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Accès SQL aux comptes contre la base sidekey_test chargée (make db-seed) : requêtes paramétrées,
 * insensibilité à la casse, rôles, projection sans secret, injection SQL, doublons.
 */
#[Group('database')]
final class UtilisateurRepositoryTest extends KernelTestCase
{
    private const PREFIXE = 'repo-test-';

    private Connection $connexion;
    private UtilisateurRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $connexion = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $this->connexion = $connexion;
        $this->repository = new UtilisateurRepository($connexion);
        $this->nettoyer();
    }

    protected function tearDown(): void
    {
        $this->nettoyer();
        parent::tearDown();
    }

    public function testCreerUnClientCreeLeCompteEtSonRole(): void
    {
        $id = $this->repository->creerClient(self::PREFIXE.'a@example.test', 'hash-de-test', 'Ana', 'Roy', 'en');

        $compte = $this->repository->parId($id);
        self::assertNotNull($compte);
        self::assertSame(self::PREFIXE.'a@example.test', $compte->email);
        self::assertSame(['CLIENT'], $compte->roles);
        self::assertSame('en', $compte->langue);
        self::assertTrue($compte->actif);
        self::assertFalse($compte->emailVerifie);
        self::assertFalse($compte->a2fActive);
        self::assertSame('hash-de-test', $compte->motDePasseHash);
    }

    public function testEmailInsensibleALaCasse(): void
    {
        $id = $this->repository->creerClient(self::PREFIXE.'casse@example.test', 'h', 'Ana', 'Roy', 'fr');

        self::assertSame($id, $this->repository->parEmail(strtoupper(self::PREFIXE).'CASSE@EXAMPLE.TEST')?->id);
    }

    public function testUnEmailDejaPrisEstRefuseParLIndexUniqueMemeAvecUneAutreCasse(): void
    {
        $this->repository->creerClient(self::PREFIXE.'dup@example.test', 'h', 'Ana', 'Roy', 'fr');

        $this->expectException(EmailDejaUtiliseException::class);
        $this->repository->creerClient(strtoupper(self::PREFIXE).'DUP@example.test', 'h', 'Bob', 'Roy', 'fr');
    }

    public function testUnDoublonNeLaisseAucunCompteOrphelin(): void
    {
        $this->repository->creerClient(self::PREFIXE.'orph@example.test', 'h', 'Ana', 'Roy', 'fr');
        try {
            $this->repository->creerClient(self::PREFIXE.'orph@example.test', 'h', 'Bob', 'Roy', 'fr');
        } catch (EmailDejaUtiliseException) {
        }

        self::assertSame(1, (int) $this->connexion->fetchOne('SELECT count(*) FROM utilisateur WHERE email LIKE :p', ['p' => self::PREFIXE.'orph%']));
        self::assertSame(1, (int) $this->connexion->fetchOne(
            'SELECT count(*) FROM utilisateur_role ur JOIN utilisateur u ON u.id = ur.utilisateur_id WHERE u.email LIKE :p',
            ['p' => self::PREFIXE.'orph%'],
        ));
    }

    public function testUnCompteDuJeuDEssaiAvecSesRolesEtSonA2f(): void
    {
        $admin = $this->repository->parEmail('admin@sidekey.test');
        self::assertNotNull($admin, 'le jeu d\'essai doit être chargé (make db-seed)');
        self::assertSame(['ADMIN'], $admin->roles);
        self::assertTrue($admin->a2fActive);
        self::assertTrue($admin->actif);

        self::assertSame(['GESTIONNAIRE'], $this->repository->parEmail('gestionnaire@sidekey.test')?->roles);
        self::assertSame(['MODERATEUR'], $this->repository->parEmail('moderateur@sidekey.test')?->roles);
    }

    public function testLeCompteDesactiveEstLuAvecActifFaux(): void
    {
        self::assertFalse($this->repository->parEmail('client15@sidekey.test')?->actif, 'compte n° 18 du jeu d\'essai');
    }

    public function testParIdCompteInconnuOuForge(): void
    {
        self::assertNull($this->repository->parId('00000000-0000-4000-8000-000000000000'));
        self::assertNull($this->repository->parId('pas-un-uuid'), 'jamais de requête avec une valeur qui n\'est pas un UUID');
        self::assertNull($this->repository->parId("1' OR '1'='1"));
        self::assertNull($this->repository->parId(''));
    }

    /** @return iterable<string, array{string}> */
    public static function injections(): iterable
    {
        yield 'guillemet' => ["admin@sidekey.test'"];
        yield 'OR 1=1' => ["' OR 1=1 --"];
        yield 'union' => ["' UNION SELECT id, mot_de_passe_hash FROM utilisateur --"];
        yield 'point-virgule' => ["x'; DROP TABLE utilisateur; --"];
        yield 'joker LIKE' => ['%'];
        yield 'joker LIKE 2' => ['admin@sidekey.test%'];
        yield 'octet nul' => ["admin@sidekey.test\0"];
    }

    #[DataProvider('injections')]
    public function testInjectionSqlViaLEmail(string $email): void
    {
        $avant = (int) $this->connexion->fetchOne('SELECT count(*) FROM utilisateur');

        try {
            self::assertNull($this->repository->parEmail($email), 'une charge d\'injection n\'est qu\'un e-mail inconnu');
        } catch (\Throwable $erreur) {
            // L'octet nul est refusé par le pilote : aussi sûr qu'un résultat vide.
            self::assertStringContainsString("\0", $email);
        }

        self::assertSame($avant, (int) $this->connexion->fetchOne('SELECT count(*) FROM utilisateur'), 'la table est intacte');
    }

    public function testEnregistrerConnexionMetAJourLaDateEtGardeLeHashParDefaut(): void
    {
        $id = $this->repository->creerClient(self::PREFIXE.'conn@example.test', 'ancien-hash', 'Ana', 'Roy', 'fr');
        self::assertNull($this->connexion->fetchOne('SELECT derniere_connexion FROM utilisateur WHERE id = :id::uuid', ['id' => $id]) ?: null);

        $this->repository->enregistrerConnexion($id);

        self::assertNotEmpty($this->connexion->fetchOne('SELECT derniere_connexion FROM utilisateur WHERE id = :id::uuid', ['id' => $id]));
        self::assertSame('ancien-hash', $this->repository->parId($id)?->motDePasseHash);
    }

    public function testEnregistrerConnexionRemplaceLeHashQuandOnLeDemande(): void
    {
        $id = $this->repository->creerClient(self::PREFIXE.'rehash@example.test', 'ancien-hash', 'Ana', 'Roy', 'fr');

        $this->repository->enregistrerConnexion($id, '$argon2id$nouveau');

        self::assertSame('$argon2id$nouveau', $this->repository->parId($id)?->motDePasseHash);
    }

    public function testLaProjectionNeLitNiLeSecretTotpNiLesCodesDeSecours(): void
    {
        $compte = $this->repository->parEmail('admin@sidekey.test');
        self::assertNotNull($compte);

        $proprietes = array_keys(get_object_vars($compte));
        foreach ($proprietes as $propriete) {
            self::assertStringNotContainsStringIgnoringCase('totp', $propriete);
            self::assertStringNotContainsStringIgnoringCase('secours', $propriete);
        }
    }

    public function testUnCompteAnonymiseNEstPlusTrouve(): void
    {
        $id = $this->repository->creerClient(self::PREFIXE.'anon@example.test', 'h', 'Ana', 'Roy', 'fr');
        $this->connexion->executeStatement('UPDATE utilisateur SET anonymise_le = now() WHERE id = :id::uuid', ['id' => $id]);

        self::assertNull($this->repository->parId($id), 'RGPD : un compte anonymisé ne peut plus se connecter');
        self::assertNull($this->repository->parEmail(self::PREFIXE.'anon@example.test'));
    }

    private function nettoyer(): void
    {
        $this->connexion->executeStatement('DELETE FROM utilisateur_role WHERE utilisateur_id IN (SELECT id FROM utilisateur WHERE email LIKE :p)', ['p' => self::PREFIXE.'%']);
        $this->connexion->executeStatement('DELETE FROM utilisateur WHERE email LIKE :p', ['p' => self::PREFIXE.'%']);
    }
}
