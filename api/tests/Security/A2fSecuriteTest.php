<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\A2f\A2fWebTestCase;
use App\Tests\Support\Auth\JwtForge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sécurité de l'A2F (CDC §11.4, §14.4, OWASP A07) : pas de back-office sans second facteur, jeton intermédiaire à portée
 * limitée et à usage unique, tentatives limitées, code TOTP non rejouable, code de secours à usage unique, secrets
 * chiffrés ou hachés au repos, aucune fuite dans les réponses, le journal d'audit et les logs.
 */
#[Group('database')]
#[Group('redis')]
final class A2fSecuriteTest extends A2fWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.70';
    }

    // --- Pas de back-office sans second facteur ------------------------------------------------

    public function testLeMotDePasseSeulNeDonneAucunAccesAuBackOffice(): void
    {
        $compte = $this->creerBackOffice();

        $etat = $this->connecter($compte['email']);

        self::assertArrayNotHasKey('jetonAcces', $etat['corps']);
        self::assertNull($etat['refresh'], 'pas de cookie de session');
        // Le jeton intermédiaire est la seule chose remise : il n'ouvre ni le profil, ni le back-office, ni une route de rôle.
        foreach (['/api/moi', '/api/_sonde/gerer_catalogue'] as $uri) {
            $this->json('GET', $uri, jeton: (string) $etat['corps']['jetonIntermediaire']);
            self::assertSame(401, $this->etat()['statut'], $uri);
        }
        $this->json('POST', '/api/admin/offres/9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a/cles', jeton: (string) $etat['corps']['jetonIntermediaire']);
        self::assertSame(401, $this->etat()['statut']);
    }

    /** @return iterable<string, array{string}> */
    public static function permissionsDuBackOffice(): iterable
    {
        foreach (['gerer_catalogue', 'moderer', 'consulter_commandes', 'valider_commande_suspecte', 'gerer_roles_et_audit'] as $permission) {
            yield $permission => [$permission];
        }
    }

    #[DataProvider('permissionsDuBackOffice')]
    public function testUnJetonDeBackOfficeSansA2fEstRefuseMemeAvecLeBonRole(string $permission): void
    {
        // Jeton d'accès valablement signé, compte ADMIN en base, mais sans la revendication « a2f » (session ouverte avant F-04, par exemple).
        $this->json('GET', '/api/_sonde/'.$permission, jeton: $this->jetonPour('admin@sidekey.test', a2fValide: false));
        self::assertSame(403, $this->etat()['statut']);

        $this->json('GET', '/api/_sonde/'.$permission, jeton: $this->jetonPour('admin@sidekey.test', a2fValide: true));
        self::assertSame(200, $this->etat()['statut'], 'témoin : avec le second facteur, l\'accès est accordé');
    }

    public function testLeBackOfficeDeLImportDeClesExigeLeSecondFacteur(): void
    {
        $offre = '9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a';
        $this->client->request('POST', '/api/admin/offres/'.$offre.'/cles', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jetonPour('gestionnaire@sidekey.test', a2fValide: false)]);

        self::assertSame(403, $this->etat()['statut'], 'route réelle du back-office (F-08) : 403 sans second facteur, avant même d\'ouvrir le fichier');
        self::assertSame(['message' => 'Accès refusé.'], $this->etat()['corps']);
    }

    public function testLeClientNaPasBesoinDuSecondFacteur(): void
    {
        $client = $this->creerClient();
        $jeton = (string) $this->connecter($client['email'])['corps']['jetonAcces'];

        $this->json('GET', '/api/_sonde/commander', jeton: $jeton);

        self::assertSame(200, $this->etat()['statut']);
    }

    public function testModifierLaRevendicationA2fInvalideLeJeton(): void
    {
        $compte = $this->backOfficeEnrole();
        $jeton = $this->jetonPour('gestionnaire@sidekey.test', a2fValide: false);
        $charge = JwtForge::charge($jeton);
        self::assertFalse($charge['a2f']);
        $charge['a2f'] = true;   // l'attaquant se « donne » le second facteur sans pouvoir re-signer

        $this->json('GET', '/api/_sonde/gerer_catalogue', jeton: JwtForge::chargeRemplacee($jeton, $charge));

        self::assertSame(401, $this->etat()['statut']);
        unset($compte);
    }

    public function testUnJetonDeLaRevendicationNonBooleenneNEstPasUnSecondFacteur(): void
    {
        // Revendication « a2f » à la valeur « true » en texte, ou 1 : seule la valeur booléenne vraie compte.
        foreach (['true', 1, 'yes', ['x']] as $valeur) {
            $jeton = $this->signerAccesAvecA2f('gestionnaire@sidekey.test', $valeur);
            $this->json('GET', '/api/_sonde/gerer_catalogue', jeton: $jeton);
            self::assertSame(403, $this->etat()['statut'], json_encode($valeur, \JSON_THROW_ON_ERROR));
        }
    }

    // --- Jeton intermédiaire : portée, durée, usage unique --------------------------------------

    public function testLeJetonDAccesNOuvrePasLaSecondeEtape(): void
    {
        $compte = $this->backOfficeEnrole();
        $acces = (string) $compte['session']['corps']['jetonAcces'];

        foreach ([['POST', '/api/connexion/a2f'], ['POST', '/api/connexion/a2f/enrolement'], ['POST', '/api/connexion/a2f/enrolement/confirmation']] as [$methode, $uri]) {
            $this->json($methode, $uri, ['code' => $this->code($compte['secret'], 1)], jeton: $acces);
            self::assertSame(401, $this->etat()['statut'], $uri);
        }
    }

    public function testSansJetonLaSecondeEtapeEstFermee(): void
    {
        foreach (['/api/connexion/a2f', '/api/connexion/a2f/enrolement', '/api/connexion/a2f/enrolement/confirmation'] as $uri) {
            $this->json('POST', $uri, ['code' => '123456']);
            self::assertSame(401, $this->etat()['statut'], $uri);
        }
    }

    public function testUnJetonIntermediaireExpireEstRefuse(): void
    {
        $compte = $this->backOfficeEnrole();
        $charge = $this->chargeIntermediaire($compte['id']);
        $charge['iat'] = time() - 400;
        $charge['exp'] = time() - 100;   // 5 min dépassées

        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($compte['secret'], 1)], jeton: $this->signer($charge));

        self::assertSame(401, $this->etat()['statut']);
    }

    public function testLeJetonIntermediaireDure5Minutes(): void
    {
        $compte = $this->creerBackOffice();
        $jeton = (string) $this->connecter($compte['email'])['corps']['jetonIntermediaire'];

        $charge = JwtForge::charge($jeton);

        self::assertSame('a2f', $charge['typ']);
        self::assertSame(300, $charge['exp'] - $charge['iat']);
        self::assertFalse($charge['a2f'], 'la revendication a2f n\'est jamais vraie dans un jeton intermédiaire');
    }

    public function testUnJetonIntermediaireNEstJamaisUnJetonDAcces(): void
    {
        $compte = $this->backOfficeEnrole();
        $jeton = $this->jetonIntermediaire($compte['email']);
        $charge = JwtForge::charge($jeton);
        $charge['typ'] = 'access';   // l'attaquant change la portée : la signature ne correspond plus

        $this->json('GET', '/api/moi', jeton: JwtForge::chargeRemplacee($jeton, $charge));

        self::assertSame(401, $this->etat()['statut']);
    }

    // --- Tentatives limitées --------------------------------------------------------------------

    public function testCinqCodesFauxPuisLeBonCodeEstBloque(): void
    {
        $compte = $this->backOfficeEnrole();
        $jeton = $this->jetonIntermediaire($compte['email']);
        $this->redis->del('rl:a2f:'.$compte['id']);   // l'enrôlement a déjà compté une tentative

        for ($i = 1; $i <= 5; ++$i) {
            $this->json('POST', '/api/connexion/a2f', ['code' => str_pad((string) $i, 6, '0', \STR_PAD_LEFT)], jeton: $jeton);
            self::assertSame(401, $this->etat()['statut'], "tentative $i");
        }

        // 6e tentative avec le BON code : bloquée, un code à 6 chiffres ne se force pas.
        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($compte['secret'], 1)], jeton: $jeton);
        self::assertSame(429, $this->etat()['statut']);
        self::assertSame(['message' => 'Trop de tentatives. Réessayez plus tard.'], $this->etat()['corps']);
        self::assertGreaterThan(880, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertLessThanOrEqual(900, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertEqualsWithDelta(900, $this->redis->ttl('rl:a2f:'.$compte['id']), 10, 'clé rl:a2f:{userId}, fenêtre de 15 minutes');
    }

    public function testLaLimiteCouvreAussiLesCodesDeSecours(): void
    {
        $compte = $this->backOfficeEnrole();
        $jeton = $this->jetonIntermediaire($compte['email']);
        $this->redis->del('rl:a2f:'.$compte['id']);

        for ($i = 0; $i < 5; ++$i) {
            $this->json('POST', '/api/connexion/a2f', ['code' => 'AAAAA-BBBBB'], jeton: $jeton);
        }
        $this->json('POST', '/api/connexion/a2f', ['code' => $compte['secours'][0]], jeton: $jeton);

        self::assertSame(429, $this->etat()['statut'], 'un code de secours valide est bloqué aussi : pas de force brute sur les 50 bits');
    }

    public function testLaLimiteEstParCompte(): void
    {
        $a = $this->backOfficeEnrole();
        $b = $this->backOfficeEnrole();
        $this->redis->set('rl:a2f:'.$a['id'], '5', ['ex' => 900]);

        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($b['secret'], 1)], jeton: $this->jetonIntermediaire($b['email']));

        self::assertSame(200, $this->etat()['statut'], 'le blocage d\'un compte n\'affecte pas les autres');
    }

    // --- Rejeu et usage unique ------------------------------------------------------------------

    public function testUnCodeTotpNeServQuUneFois(): void
    {
        $compte = $this->backOfficeEnrole();
        $code = $this->code($compte['secret'], 1);

        $this->json('POST', '/api/connexion/a2f', ['code' => $code], jeton: $this->jetonIntermediaire($compte['email']));
        self::assertSame(200, $this->etat()['statut']);

        $this->json('POST', '/api/connexion/a2f', ['code' => $code], jeton: $this->jetonIntermediaire($compte['email']));
        self::assertSame(401, $this->etat()['statut'], 'un code observé puis rejoué dans sa fenêtre est refusé');
        $cles = (array) $this->redis->keys('a2f:used:'.$compte['id'].':*');
        self::assertNotEmpty($cles);
        self::assertLessThanOrEqual(120, $this->redis->ttl((string) $cles[0]));
    }

    public function testLeCodeDeL_EnrolementNeServPasDeuxFois(): void
    {
        $compte = $this->creerBackOffice();
        $jeton = (string) $this->connecter($compte['email'])['corps']['jetonIntermediaire'];
        $this->json('POST', '/api/connexion/a2f/enrolement', jeton: $jeton);
        $secret = (string) $this->etat()['corps']['secret'];
        $code = $this->code($secret);
        $this->json('POST', '/api/connexion/a2f/enrolement/confirmation', ['code' => $code], jeton: $jeton);
        self::assertSame(201, $this->etat()['statut']);

        // Même code, nouvelle connexion : refusé (le pas est réservé depuis l'activation).
        $this->json('POST', '/api/connexion/a2f', ['code' => $code], jeton: $this->jetonIntermediaire($compte['email']));
        self::assertSame(401, $this->etat()['statut']);
    }

    public function testLeCodeDUnAutreCompteNeServPas(): void
    {
        $a = $this->backOfficeEnrole();
        $b = $this->backOfficeEnrole();

        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($a['secret'], 1)], jeton: $this->jetonIntermediaire($b['email']));

        self::assertSame(401, $this->etat()['statut']);
    }

    public function testLesCodesDeSecoursDUnAutreCompteNeServentPas(): void
    {
        $a = $this->backOfficeEnrole();
        $b = $this->backOfficeEnrole();

        $this->json('POST', '/api/connexion/a2f', ['code' => $a['secours'][0]], jeton: $this->jetonIntermediaire($b['email']));

        self::assertSame(401, $this->etat()['statut']);
        self::assertSame(0, (int) $this->connexion->fetchOne('SELECT count(*) FROM code_secours WHERE utilisateur_id = :id::uuid AND utilise_le IS NOT NULL', ['id' => $a['id']]), 'le code de A n\'est pas consommé');
    }

    // --- Secrets au repos et aucune fuite -------------------------------------------------------

    public function testAucunSecretNiCodeEnClairEnBase(): void
    {
        $compte = $this->backOfficeEnrole();

        $dump = json_encode([
            $this->connexion->fetchAllAssociative('SELECT id, email, mot_de_passe_hash, encode(totp_secret_chiffre, \'hex\') AS secret FROM utilisateur WHERE id = :id::uuid', ['id' => $compte['id']]),
            $this->connexion->fetchAllAssociative('SELECT * FROM code_secours WHERE utilisateur_id = :id::uuid', ['id' => $compte['id']]),
            $this->connexion->fetchAllAssociative('SELECT action, ressource, ressource_id, encode(ip_hachee, \'hex\') AS ip, utilisateur_id FROM journal_audit WHERE utilisateur_id = :id::uuid', ['id' => $compte['id']]),
        ], \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($compte['secret'], $dump);
        self::assertStringNotContainsString(bin2hex($compte['secret']), $dump);
        foreach ($compte['secours'] as $code) {
            self::assertStringNotContainsString($code, $dump);
            self::assertStringNotContainsString(str_replace('-', '', $code), $dump);
        }
    }

    public function testLesReponsesNeRepetentJamaisLeSecretNiLesCodes(): void
    {
        $compte = $this->backOfficeEnrole();
        $reponses = [];

        $this->connecter($compte['email']);
        $reponses[] = (string) $this->client->getResponse()->getContent();
        $this->json('POST', '/api/connexion/a2f', ['code' => '000000'], jeton: $this->jetonIntermediaire($compte['email']));
        $reponses[] = (string) $this->client->getResponse()->getContent();
        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($compte['secret'], 1)], jeton: $this->jetonIntermediaire($compte['email']));
        $reponses[] = (string) $this->client->getResponse()->getContent();
        $this->json('GET', '/api/moi', jeton: (string) $compte['session']['corps']['jetonAcces']);
        $reponses[] = (string) $this->client->getResponse()->getContent();

        foreach ($reponses as $contenu) {
            self::assertStringNotContainsString($compte['secret'], $contenu);
            self::assertStringNotContainsString('otpauth', $contenu);
            foreach ($compte['secours'] as $code) {
                self::assertStringNotContainsString($code, $contenu);
            }
        }
    }

    public function testLeJournalDAuditTraceSansRienDeSensible(): void
    {
        $compte = $this->backOfficeEnrole();
        $this->json('POST', '/api/connexion/a2f', ['code' => '000000'], jeton: $this->jetonIntermediaire($compte['email']));
        $this->json('POST', '/api/connexion/a2f', ['code' => $compte['secours'][0]], jeton: $this->jetonIntermediaire($compte['email']));

        $actions = $this->connexion->fetchFirstColumn('SELECT action FROM journal_audit WHERE utilisateur_id = :id::uuid ORDER BY cree_le', ['id' => $compte['id']]);

        self::assertSame(['A2F_ACTIVATION', 'A2F_ECHEC', 'A2F_CODE_SECOURS_UTILISE'], $actions);
        $ips = $this->connexion->fetchFirstColumn('SELECT encode(ip_hachee, \'hex\') FROM journal_audit WHERE utilisateur_id = :id::uuid', ['id' => $compte['id']]);
        foreach ($ips as $ip) {
            self::assertStringNotContainsString(static::adresseIp(), (string) $ip, 'adresse IP hachée, jamais en clair');
            self::assertSame(64, \strlen((string) $ip));
        }
    }

    public function testLesLogsDeLApplicationNeContiennentNiSecretNiCode(): void
    {
        $compte = $this->backOfficeEnrole();
        $this->json('POST', '/api/connexion/a2f', ['code' => $compte['secours'][1]], jeton: $this->jetonIntermediaire($compte['email']));

        $journal = static::getContainer()->getParameter('kernel.cache_dir').'/journal.log';
        $contenu = is_file($journal) ? (string) file_get_contents($journal) : '';

        self::assertStringNotContainsString($compte['secret'], $contenu);
        foreach ($compte['secours'] as $code) {
            self::assertStringNotContainsString($code, $contenu);
            self::assertStringNotContainsString(str_replace('-', '', $code), $contenu);
        }
    }

    // --- Entrées hostiles -----------------------------------------------------------------------

    /** @return iterable<string, array{mixed}> */
    public static function codesHostiles(): iterable
    {
        yield 'injection SQL' => ["123456' OR '1'='1"];
        yield 'DROP' => ['1; DROP TABLE code_secours;--'];
        yield 'tableau' => [['123456']];
        yield 'objet' => [['$ne' => '']];
        yield 'nombre' => [123456];
        yield 'booléen' => [true];
        yield 'nul' => [null];
        yield 'très long' => [str_repeat('1', 100_000)];
        yield 'octet nul' => ["12345\0"];
    }

    #[DataProvider('codesHostiles')]
    public function testCodesHostilesRefusesSansErreurInterne(mixed $code): void
    {
        $compte = $this->backOfficeEnrole();
        $avant = (int) $this->connexion->fetchOne('SELECT count(*) FROM code_secours');

        $this->json('POST', '/api/connexion/a2f', ['code' => $code], jeton: $this->jetonIntermediaire($compte['email']));

        self::assertContains($this->etat()['statut'], [401, 422], 'jamais un 500');
        self::assertSame($avant, (int) $this->connexion->fetchOne('SELECT count(*) FROM code_secours'), 'table intacte');
        self::assertStringNotContainsString('SQLSTATE', (string) $this->client->getResponse()->getContent());
    }

    public function testLeCorpsNeChoisitNiLeSecretNiLeRoleNiLEtat(): void
    {
        $compte = $this->creerBackOffice();
        $jeton = (string) $this->connecter($compte['email'])['corps']['jetonIntermediaire'];

        // L'attaquant tente d'imposer son propre secret, d'activer l'A2F ou de changer de compte.
        $this->json('POST', '/api/connexion/a2f/enrolement', ['secret' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', 'a2fActive' => true, 'utilisateurId' => '00000000-0000-4000-8000-000000000001'], jeton: $jeton);

        self::assertSame(200, $this->etat()['statut']);
        self::assertNotSame('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA', $this->etat()['corps']['secret'], 'le secret est toujours tiré par le serveur');
        self::assertFalse((bool) $this->connexion->fetchOne('SELECT a2f_active FROM utilisateur WHERE id = :id::uuid', ['id' => $compte['id']]));
    }

    public function testLesErreursNeDisentPasLequelDesControlesAEchoue(): void
    {
        $compte = $this->backOfficeEnrole();
        $jeton = $this->jetonIntermediaire($compte['email']);

        $this->json('POST', '/api/connexion/a2f', ['code' => '000000'], jeton: $jeton);
        $faux = $this->etat()['corps'];
        $this->json('POST', '/api/connexion/a2f', ['code' => 'AAAAA-BBBBB'], jeton: $jeton);
        $secoursFaux = $this->etat()['corps'];
        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($compte['secret'], -1) === $this->code($compte['secret'], 0) ? '111111' : $this->code($compte['secret'], -5)], jeton: $jeton);
        $expire = $this->etat()['corps'];

        self::assertSame($faux, $secoursFaux, 'code TOTP faux et code de secours faux : même réponse');
        self::assertSame($faux, $expire, 'code expiré : même réponse');
    }

    /** @return array<string, mixed> */
    private function chargeIntermediaire(string $compteId): array
    {
        return [
            'sub' => $compteId, 'jti' => bin2hex(random_bytes(16)),
            'fam' => \sprintf('%s-%s-4%s-8%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(6))),
            'typ' => 'a2f', 'a2f' => false, 'iat' => time(), 'exp' => time() + 300,
        ];
    }

    /** @param array<string, mixed> $charge */
    private function signer(array $charge): string
    {
        $cle = (string) file_get_contents(\dirname(__DIR__, 2).'/config/jwt/private.pem');

        return JwtForge::rs256($charge, $cle, (string) ($_SERVER['JWT_PASSPHRASE'] ?? $_ENV['JWT_PASSPHRASE'] ?? ''));
    }

    private function signerAccesAvecA2f(string $email, mixed $valeurA2f): string
    {
        $id = (string) $this->connexion->fetchOne('SELECT id::text FROM utilisateur WHERE email = :e', ['e' => $email]);
        $charge = $this->chargeIntermediaire($id);
        $charge['typ'] = 'access';
        $charge['a2f'] = $valeurA2f;

        return $this->signer($charge);
    }
}
