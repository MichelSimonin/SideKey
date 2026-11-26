<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Tests\Support\A2f\A2fWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Seconde étape de la connexion de bout en bout (CDC §11.4) : HTTP → pare-feu → services → PostgreSQL et Redis réels.
 * Parcours nominaux et cas d'exception ; les attaques sont dans tests/Security/A2fSecuriteTest.
 */
#[Group('database')]
#[Group('redis')]
final class A2fControllerTest extends A2fWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.60';
    }

    public function testUnCompteDuBackOfficeSansA2fNObtientQueL_Enrolement(): void
    {
        $compte = $this->creerBackOffice();

        $etat = $this->connecter($compte['email']);

        self::assertSame(200, $etat['statut']);
        self::assertSame('a2f_enrolement', $etat['corps']['etape']);
        self::assertSame(['etape', 'jetonIntermediaire', 'expireDans'], array_keys($etat['corps']), 'ni jeton d\'accès ni utilisateur');
        self::assertNull($etat['refresh'], 'aucun refresh sans second facteur');
        self::assertNull($etat['csrf']);
    }

    public function testLesTroisRolesDuBackOfficeSontConcernes(): void
    {
        foreach (['GESTIONNAIRE', 'MODERATEUR', 'ADMIN'] as $role) {
            self::assertSame('a2f_enrolement', $this->connecter($this->creerBackOffice($role)['email'])['corps']['etape'], $role);
        }
    }

    public function testUnClientSeConnecteSansSecondeEtape(): void
    {
        $etat = $this->connecter($this->creerClient()['email']);

        self::assertSame('connecte', $etat['corps']['etape'], 'A2F facultative pour les clients');
    }

    public function testL_EnrolementRenvoieSecretUriEtQrCode(): void
    {
        $jeton = (string) $this->connecter($this->creerBackOffice()['email'])['corps']['jetonIntermediaire'];

        $this->json('POST', '/api/connexion/a2f/enrolement', jeton: $jeton);
        $etat = $this->etat();

        self::assertSame(200, $etat['statut']);
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $etat['corps']['secret']);
        self::assertStringStartsWith('otpauth://totp/SideKey%3Aa2f-test-gestionnaire-', $etat['corps']['uri']);
        self::assertStringContainsString('secret='.$etat['corps']['secret'], $etat['corps']['uri']);
        self::assertStringContainsString('issuer=SideKey', $etat['corps']['uri']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $etat['corps']['qrCode']);
        $svg = (string) base64_decode(substr($etat['corps']['qrCode'], \strlen('data:image/svg+xml;base64,')), true);
        self::assertStringContainsString('<svg', $svg);
        self::assertStringNotContainsString('<script', $svg);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'le secret n\'est jamais mis en cache');
    }

    public function testLeSecretEnAttenteEstChiffreEnBaseEtLA2fPasEncoreActive(): void
    {
        $compte = $this->creerBackOffice();
        $jeton = (string) $this->connecter($compte['email'])['corps']['jetonIntermediaire'];
        $this->json('POST', '/api/connexion/a2f/enrolement', jeton: $jeton);
        $secret = (string) $this->etat()['corps']['secret'];

        $ligne = $this->connexion->fetchAssociative('SELECT encode(totp_secret_chiffre, \'hex\') AS secret, a2f_active FROM utilisateur WHERE id = :id::uuid', ['id' => $compte['id']]);

        self::assertIsArray($ligne);
        self::assertFalse($ligne['a2f_active']);
        self::assertStringStartsWith('01', $ligne['secret'], 'octet de version du chiffré AES-GCM');
        self::assertStringNotContainsString(bin2hex($secret), $ligne['secret']);
        self::assertSame(1 + 12 + 16 + 32, \strlen((string) hex2bin($ligne['secret'])), 'version, IV, étiquette, secret de 32 caractères');
    }

    public function testRecommencerL_EnrolementRemplaceLeSecretEnAttente(): void
    {
        $jeton = (string) $this->connecter($this->creerBackOffice()['email'])['corps']['jetonIntermediaire'];
        $this->json('POST', '/api/connexion/a2f/enrolement', jeton: $jeton);
        $ancien = (string) $this->etat()['corps']['secret'];
        $this->json('POST', '/api/connexion/a2f/enrolement', jeton: $jeton);
        $nouveau = (string) $this->etat()['corps']['secret'];

        self::assertNotSame($ancien, $nouveau);
        $this->json('POST', '/api/connexion/a2f/enrolement/confirmation', ['code' => $this->code($ancien)], jeton: $jeton);
        self::assertSame(401, $this->etat()['statut'], 'le code de l\'ancien secret ne confirme plus rien');
        $this->json('POST', '/api/connexion/a2f/enrolement/confirmation', ['code' => $this->code($nouveau)], jeton: $jeton);
        self::assertSame(201, $this->etat()['statut']);
    }

    public function testLaConfirmationActiveLA2fDonneLaSessionEtDixCodesDeSecours(): void
    {
        $compte = $this->backOfficeEnrole();
        $etat = $compte['session'];

        self::assertSame('connecte', $etat['corps']['etape']);
        self::assertCount(10, $etat['corps']['codesSecours']);
        self::assertCount(10, array_unique($etat['corps']['codesSecours']));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{5}-[A-Z2-7]{5}$/', $etat['corps']['codesSecours'][0]);
        self::assertSame(['GESTIONNAIRE'], $etat['corps']['utilisateur']['roles']);
        self::assertTrue($etat['corps']['utilisateur']['a2fActive'] ?? false);
        self::assertNotNull($etat['refresh'], 'cookie de rafraîchissement');
        self::assertNotNull($etat['csrf']);
        self::assertTrue((bool) $this->connexion->fetchOne('SELECT a2f_active FROM utilisateur WHERE id = :id::uuid', ['id' => $compte['id']]));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), 'codes de secours : jamais en cache');
    }

    public function testLesCodesDeSecoursSontStockesHaches(): void
    {
        $compte = $this->backOfficeEnrole();

        $hachages = $this->connexion->fetchFirstColumn('SELECT code_hash FROM code_secours WHERE utilisateur_id = :id::uuid', ['id' => $compte['id']]);

        self::assertCount(10, $hachages);
        foreach ($hachages as $hache) {
            self::assertStringStartsWith('$argon2id$', $hache);
            foreach ($compte['secours'] as $clair) {
                self::assertStringNotContainsString(str_replace('-', '', $clair), $hache);
            }
        }
        self::assertSame(0, (int) $this->connexion->fetchOne('SELECT count(*) FROM code_secours WHERE utilisateur_id = :id::uuid AND utilise_le IS NOT NULL', ['id' => $compte['id']]));
    }

    public function testApresL_EnrolementLeBackOfficeEstAccessible(): void
    {
        $compte = $this->backOfficeEnrole();

        $this->json('GET', '/api/_sonde/gerer_catalogue', jeton: (string) $compte['session']['corps']['jetonAcces']);

        self::assertSame(200, $this->etat()['statut'], 'session avec second facteur : back-office ouvert');
    }

    public function testLaConnexionSuivanteDemandeLeCodeEtDonneLaSession(): void
    {
        $compte = $this->backOfficeEnrole();
        $this->client->getCookieJar()->clear();

        $connexion = $this->connecter($compte['email']);
        self::assertSame('a2f', $connexion['corps']['etape'], 'étape « code », plus d\'enrôlement');
        self::assertNull($connexion['refresh']);

        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($compte['secret'], 1)], jeton: (string) $connexion['corps']['jetonIntermediaire']);
        $etat = $this->etat();

        self::assertSame(200, $etat['statut']);
        self::assertSame('connecte', $etat['corps']['etape']);
        self::assertArrayNotHasKey('codesSecours', $etat['corps'], 'les codes ne sont montrés qu\'une fois');
        self::assertNotNull($etat['refresh']);
        $this->json('GET', '/api/_sonde/gerer_catalogue', jeton: (string) $etat['corps']['jetonAcces']);
        self::assertSame(200, $this->etat()['statut']);
    }

    public function testUnCodeDeSecoursOuvreLaSessionUneSeuleFois(): void
    {
        $compte = $this->backOfficeEnrole();
        $code = $compte['secours'][3];

        $this->json('POST', '/api/connexion/a2f', ['code' => strtolower($code)], jeton: $this->jetonIntermediaire($compte['email']));
        self::assertSame(200, $this->etat()['statut'], 'casse ignorée');
        self::assertSame(1, (int) $this->connexion->fetchOne('SELECT count(*) FROM code_secours WHERE utilisateur_id = :id::uuid AND utilise_le IS NOT NULL', ['id' => $compte['id']]));

        $this->json('POST', '/api/connexion/a2f', ['code' => $code], jeton: $this->jetonIntermediaire($compte['email']));
        self::assertSame(401, $this->etat()['statut'], 'usage unique');
        self::assertSame(['message' => 'Code incorrect ou expiré.', 'raison' => 'code_invalide'], $this->etat()['corps']);
    }

    public function testLeRafraichissementGardeL_AccesAuBackOffice(): void
    {
        $compte = $this->backOfficeEnrole();
        $session = $compte['session'];

        $this->rafraichir($session['refresh'], $session['csrf']);
        $suite = $this->etat();

        self::assertSame(200, $suite['statut']);
        $this->json('GET', '/api/_sonde/gerer_catalogue', jeton: (string) $suite['corps']['jetonAcces']);
        self::assertSame(200, $this->etat()['statut'], 'la revendication a2f survit à la rotation');
    }

    public function testLeJetonIntermediaireNeSertQuUneFois(): void
    {
        $compte = $this->backOfficeEnrole();
        $jeton = $this->jetonIntermediaire($compte['email']);

        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($compte['secret'], 1)], jeton: $jeton);
        self::assertSame(200, $this->etat()['statut']);

        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($compte['secret'], -1)], jeton: $jeton);
        self::assertSame(401, $this->etat()['statut'], 'jeton intermédiaire révoqué après usage : pas de seconde session');
        self::assertSame(['message' => 'Authentification requise.'], $this->etat()['corps']);
    }

    public function testUnMauvaisCodeDonne401EtLeJetonResteUtilisable(): void
    {
        $compte = $this->backOfficeEnrole();
        $jeton = $this->jetonIntermediaire($compte['email']);

        $this->json('POST', '/api/connexion/a2f', ['code' => '000000'], jeton: $jeton);
        self::assertSame(401, $this->etat()['statut']);
        self::assertSame('code_invalide', $this->etat()['corps']['raison']);
        self::assertNull($this->etat()['refresh']);

        $this->json('POST', '/api/connexion/a2f', ['code' => $this->code($compte['secret'], 1)], jeton: $jeton);
        self::assertSame(200, $this->etat()['statut'], 'un nouvel essai reste possible');
    }

    public function testValidationDuFormatDuCode(): void
    {
        $compte = $this->backOfficeEnrole();
        $jeton = $this->jetonIntermediaire($compte['email']);

        foreach (['', '12345', '1234567', 'abcdef', 'ABCDE-FGHJ', '123456; DROP TABLE utilisateur', 'SideKey-Dev-2026!'] as $code) {
            $this->json('POST', '/api/connexion/a2f', ['code' => $code], jeton: $jeton);
            self::assertSame(422, $this->etat()['statut'], $code);
            self::assertArrayHasKey('code', $this->etat()['corps']['erreurs']);
        }
        $this->json('POST', '/api/connexion/a2f', ['code' => ['123456']], jeton: $jeton);
        self::assertSame(422, $this->etat()['statut'], 'un tableau n\'est pas un code');
        $this->json('POST', '/api/connexion/a2f', [], jeton: $jeton);
        self::assertSame(422, $this->etat()['statut']);
    }

    public function testSeulLeJsonEstAccepte(): void
    {
        $jeton = (string) $this->connecter($this->creerBackOffice()['email'])['corps']['jetonIntermediaire'];

        $this->client->request('POST', '/api/connexion/a2f/enrolement/confirmation', content: 'code=123456',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.$jeton, 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        self::assertSame(415, $this->etat()['statut']);
    }

    public function testLaValidationEstTraduiteEnAnglais(): void
    {
        $jeton = (string) $this->connecter($this->creerBackOffice()['email'])['corps']['jetonIntermediaire'];

        $this->json('POST', '/api/connexion/a2f/enrolement/confirmation', ['code' => 'x'], jeton: $jeton, serveur: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertSame('Enter the 6-digit code from your app, or a backup code (ABCDE-FGHJK).', $this->etat()['corps']['erreurs']['code']);
    }

    public function testLesEtatsIncompatiblesDonnent409(): void
    {
        $actif = $this->backOfficeEnrole();
        $sansA2f = $this->creerBackOffice();

        // Vérifier un compte sans A2F
        $this->json('POST', '/api/connexion/a2f', ['code' => '123456'], jeton: (string) $this->connecter($sansA2f['email'])['corps']['jetonIntermediaire']);
        self::assertSame(409, $this->etat()['statut']);
        self::assertSame('La double authentification n\'est pas activée sur ce compte.', $this->etat()['corps']['message']);

        // Confirmer sans avoir commencé
        $this->json('POST', '/api/connexion/a2f/enrolement/confirmation', ['code' => '123456'], jeton: (string) $this->connecter($sansA2f['email'])['corps']['jetonIntermediaire']);
        self::assertSame(409, $this->etat()['statut']);

        // Enrôler un compte déjà protégé
        $jeton = $this->jetonIntermediaire($actif['email']);
        $this->json('POST', '/api/connexion/a2f/enrolement', jeton: $jeton);
        self::assertSame(409, $this->etat()['statut']);
        $this->json('POST', '/api/connexion/a2f/enrolement/confirmation', ['code' => '123456'], jeton: $jeton);
        self::assertSame(409, $this->etat()['statut']);
    }

    public function testLeSecretActifNEstJamaisEcrase(): void
    {
        $actif = $this->backOfficeEnrole();
        $avant = (string) $this->connexion->fetchOne('SELECT encode(totp_secret_chiffre, \'hex\') FROM utilisateur WHERE id = :id::uuid', ['id' => $actif['id']]);

        $this->json('POST', '/api/connexion/a2f/enrolement', jeton: $this->jetonIntermediaire($actif['email']));

        self::assertSame(409, $this->etat()['statut']);
        self::assertSame($avant, (string) $this->connexion->fetchOne('SELECT encode(totp_secret_chiffre, \'hex\') FROM utilisateur WHERE id = :id::uuid', ['id' => $actif['id']]), 'un attaquant qui possède le mot de passe ne peut pas remplacer le secret');
    }

    public function testUnCompteDuJeuDEssaiAvecUnSecretFactifRefuseLeCodeSansErreur500(): void
    {
        $etat = $this->connecter('admin@sidekey.test', self::MOT_DE_PASSE_JEU_ESSAI);
        self::assertSame('a2f', $etat['corps']['etape']);

        $this->json('POST', '/api/connexion/a2f', ['code' => '123456'], jeton: (string) $etat['corps']['jetonIntermediaire']);

        self::assertSame(401, $this->etat()['statut'], 'le secret factice du jeu d\'essai est illisible : code refusé, pas de 500');
        self::assertSame('code_invalide', $this->etat()['corps']['raison']);
        $this->redis->del('rl:a2f:'.(string) $this->connexion->fetchOne("SELECT id::text FROM utilisateur WHERE email = 'admin@sidekey.test'"));   // compte du jeu d'essai : on remet son compteur à zéro
    }
}
