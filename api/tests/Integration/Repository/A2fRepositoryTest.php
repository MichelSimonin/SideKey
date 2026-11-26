<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\A2fRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Accès SQL de l'A2F sur sidekey_test (make db-seed) : secret binaire, activation tout ou rien, codes de secours à usage
 * unique, identifiants forgés. Les comptes « a2f-repo-… » ne sont pas supprimés (le journal d'audit les référence ailleurs
 * et les codes ne se suppriment pas sans le compte) : leurs courriels sont uniques.
 */
#[Group('database')]
final class A2fRepositoryTest extends KernelTestCase
{
    private A2fRepository $repository;
    private Connection $connexion;

    protected function setUp(): void
    {
        self::bootKernel();
        $connexion = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $this->connexion = $connexion;
        $this->repository = new A2fRepository($connexion);
    }

    protected function tearDown(): void
    {
        $this->connexion->executeStatement("DELETE FROM code_secours WHERE utilisateur_id IN (SELECT id FROM utilisateur WHERE email LIKE 'a2f-repo-%')");
        $this->connexion->executeStatement("DELETE FROM utilisateur_role WHERE utilisateur_id IN (SELECT id FROM utilisateur WHERE email LIKE 'a2f-repo-%')");
        $this->connexion->executeStatement("DELETE FROM utilisateur WHERE email LIKE 'a2f-repo-%'");
        parent::tearDown();
    }

    public function testUnCompteSansSecret(): void
    {
        self::assertNull($this->repository->secretChiffre($this->compte()));
    }

    public function testLeSecretBinaireFaitL_AllerRetourOctetPourOctet(): void
    {
        $id = $this->compte();
        $octets = "\x01".random_bytes(60)."\x00\xff";   // octets arbitraires, nuls compris

        self::assertTrue($this->repository->enregistrerSecretEnAttente($id, $octets));

        self::assertSame($octets, $this->repository->secretChiffre($id));
    }

    public function testLeSecretEnAttenteSeRemplace(): void
    {
        $id = $this->compte();
        $this->repository->enregistrerSecretEnAttente($id, 'premier');
        $this->repository->enregistrerSecretEnAttente($id, 'second');

        self::assertSame('second', $this->repository->secretChiffre($id));
    }

    public function testUnSecretActifNEstJamaisEcrase(): void
    {
        $id = $this->compte();
        $this->repository->enregistrerSecretEnAttente($id, 'secret-actif');
        $this->repository->activer($id, [$this->hache('A')]);

        self::assertFalse($this->repository->enregistrerSecretEnAttente($id, 'tentative-de-remplacement'));
        self::assertSame('secret-actif', $this->repository->secretChiffre($id));
    }

    public function testActiverPoseLesCodesEtL_ActivationDansUneSeuleOperation(): void
    {
        $id = $this->compte();
        $this->repository->enregistrerSecretEnAttente($id, 'secret');

        $ok = $this->repository->activer($id, array_map($this->hache(...), range('A', 'J')));

        self::assertTrue($ok);
        self::assertTrue((bool) $this->connexion->fetchOne('SELECT a2f_active FROM utilisateur WHERE id = :id::uuid', ['id' => $id]));
        self::assertCount(10, $this->repository->codesSecoursDisponibles($id));
    }

    public function testActiverSansSecretEnAttenteNeCreeRien(): void
    {
        $id = $this->compte();

        self::assertFalse($this->repository->activer($id, [$this->hache('A')]));

        self::assertFalse((bool) $this->connexion->fetchOne('SELECT a2f_active FROM utilisateur WHERE id = :id::uuid', ['id' => $id]));
        self::assertSame([], $this->repository->codesSecoursDisponibles($id), 'tout ou rien : aucun code orphelin');
    }

    public function testActiverDeuxFoisNeRemplacePasLesCodes(): void
    {
        $id = $this->compte();
        $this->repository->enregistrerSecretEnAttente($id, 'secret');
        $this->repository->activer($id, [$this->hache('A'), $this->hache('B')]);

        self::assertFalse($this->repository->activer($id, [$this->hache('Z')]), 'déjà active');

        self::assertCount(2, $this->repository->codesSecoursDisponibles($id), 'les codes d\'origine ne sont pas touchés par un second appel');
    }

    public function testUnNouveauLotSupprimeLesAnciensCodes(): void
    {
        // Décision du 24/11/2026 : les 10 codes sont régénérés en bloc, les anciens sont supprimés dans la même transaction.
        $id = $this->compte();
        $this->connexion->executeStatement("INSERT INTO code_secours (code_hash, utilisateur_id) VALUES ('ancien-1', :id::uuid), ('ancien-2', :id::uuid)", ['id' => $id]);
        $this->repository->enregistrerSecretEnAttente($id, 'secret');

        $this->repository->activer($id, [$this->hache('N')]);

        $codes = $this->repository->codesSecoursDisponibles($id);
        self::assertCount(1, $codes);
        self::assertStringStartsWith('$argon2id$', $codes[0]['hache']);
    }

    public function testUnCodeNeSeConsommeQuUneFois(): void
    {
        $id = $this->compte();
        $this->repository->enregistrerSecretEnAttente($id, 'secret');
        $this->repository->activer($id, [$this->hache('A'), $this->hache('B')]);
        $codeId = $this->repository->codesSecoursDisponibles($id)[0]['id'];

        self::assertTrue($this->repository->consommerCodeSecours($codeId));
        self::assertFalse($this->repository->consommerCodeSecours($codeId), 'l\'UPDATE conditionnel ne réussit qu\'une fois');

        self::assertCount(1, $this->repository->codesSecoursDisponibles($id), 'un code consommé n\'est plus proposé');
        self::assertNotNull($this->connexion->fetchOne('SELECT utilise_le FROM code_secours WHERE id = :id::uuid', ['id' => $codeId]));
    }

    public function testLesCodesDUnCompteNeSortentPasPourUnAutre(): void
    {
        $a = $this->compte();
        $b = $this->compte();
        $this->repository->enregistrerSecretEnAttente($a, 'secret');
        $this->repository->activer($a, [$this->hache('A')]);

        self::assertSame([], $this->repository->codesSecoursDisponibles($b));
    }

    public function testIdentifiantsForgesRefuses(): void
    {
        foreach (["x'; DROP TABLE code_secours; --", 'pas-un-uuid', '', "00000000-0000-4000-8000-000000000000' OR '1'='1"] as $id) {
            foreach ([
                fn () => $this->repository->secretChiffre($id),
                fn () => $this->repository->enregistrerSecretEnAttente($id, 'x'),
                fn () => $this->repository->activer($id, []),
                fn () => $this->repository->codesSecoursDisponibles($id),
                fn () => $this->repository->consommerCodeSecours($id),
            ] as $appel) {
                try {
                    $appel();
                    self::fail('Identifiant forgé accepté : '.$id);
                } catch (\InvalidArgumentException) {
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testUnCompteInconnuNAPasDeSecret(): void
    {
        self::assertNull($this->repository->secretChiffre('00000000-0000-4000-8000-0000000000fe'));
        self::assertFalse($this->repository->enregistrerSecretEnAttente('00000000-0000-4000-8000-0000000000fe', 'x'));
    }

    private function compte(): string
    {
        $utilisateurs = static::getContainer()->get(UtilisateurRepository::class);
        self::assertInstanceOf(UtilisateurRepository::class, $utilisateurs);

        return $utilisateurs->creerClient('a2f-repo-'.bin2hex(random_bytes(6)).'@example.test', 'hash', 'Ana', 'Test', 'fr');
    }

    private function hache(string $code): string
    {
        return '$argon2id$v=19$m=10,t=3,p=1$'.base64_encode(str_repeat($code, 8)).'$'.base64_encode(random_bytes(16));
    }
}
