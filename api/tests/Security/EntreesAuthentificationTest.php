<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\Auth\AuthWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Entrées hostiles sur les routes d'authentification (CDC §11.3, OWASP A01, A03, A07) : injections, confusion
 * de types, mass assignment, énumération de comptes, mots de passe démesurés, fuites d'information.
 * Toutes les entrées sont contrôlées dans la couche serveur : DTO, service, requêtes paramétrées, contraintes SQL.
 */
#[Group('database')]
#[Group('redis')]
final class EntreesAuthentificationTest extends AuthWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.50';
    }

    /** @return iterable<string, array{string}> */
    public static function injectionsSql(): iterable
    {
        yield "' OR '1'='1" => ["' OR '1'='1"];
        yield "' OR 1=1 --" => ["' OR 1=1 --"];
        yield 'admin@sidekey.test\'--' => ["admin@sidekey.test'--"];
        yield 'UNION SELECT' => ["x' UNION SELECT mot_de_passe_hash FROM utilisateur --"];
        yield 'DROP TABLE' => ["x'; DROP TABLE utilisateur; --"];
        yield 'sous-requête' => ["x' || (SELECT pg_sleep(5)) || '"];
        yield 'commentaire' => ['admin@sidekey.test/*'];
    }

    #[DataProvider('injectionsSql')]
    public function testInjectionSqlViaLEmailDeConnexion(string $email): void
    {
        $avant = (int) $this->connexion->fetchOne('SELECT count(*) FROM utilisateur');

        $debut = microtime(true);
        $etat = $this->connecter($email, 'n-importe-quoi-de-long');

        self::assertSame(401, $etat['statut'], 'une injection n\'est qu\'un e-mail inconnu');
        self::assertSame(['message' => 'E-mail ou mot de passe incorrect.'], $etat['corps']);
        self::assertLessThan(4.0, microtime(true) - $debut, 'pg_sleep non exécuté');
        self::assertSame($avant, (int) $this->connexion->fetchOne('SELECT count(*) FROM utilisateur'), 'table intacte');
    }

    #[DataProvider('injectionsSql')]
    public function testInjectionSqlViaLeMotDePasse(string $motDePasse): void
    {
        $client = $this->creerClient();

        self::assertSame(401, $this->connecter($client['email'], $motDePasse)['statut']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function confusionsDeTypes(): iterable
    {
        yield 'e-mail en objet (opérateur NoSQL)' => [['email' => ['$ne' => ''], 'motDePasse' => 'x']];
        yield 'e-mail en tableau' => [['email' => ['a@b.c'], 'motDePasse' => 'x']];
        yield 'mot de passe en objet' => [['email' => 'a@b.c', 'motDePasse' => ['$gt' => '']]];
        yield 'mot de passe nombre' => [['email' => 'a@b.c', 'motDePasse' => 12345678]];
        yield 'e-mail booléen' => [['email' => true, 'motDePasse' => 'x']];
        yield 'e-mail nul' => [['email' => null, 'motDePasse' => 'x']];
        yield 'corps vide' => [[]];
    }

    /** @param array<string, mixed> $corps */
    #[DataProvider('confusionsDeTypes')]
    public function testConfusionDeTypesSurLaConnexion(array $corps): void
    {
        $this->json('POST', '/api/connexion', $corps);

        self::assertSame(422, $this->etat()['statut'], 'jamais un 500 ni une connexion réussie');
        self::assertArrayHasKey('erreurs', $this->etat()['corps']);
    }

    public function testCorpsJsonMalformeEtMauvaisTypeDeContenu(): void
    {
        $this->client->request('POST', '/api/connexion', content: '{"email": ', server: ['CONTENT_TYPE' => 'application/json']);
        self::assertContains($this->etat()['statut'], [400, 422]);

        $this->client->request('POST', '/api/connexion', content: 'email=a@b.c&motDePasse=x', server: ['CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        self::assertSame(415, $this->etat()['statut'], 'seul le JSON est accepté (un formulaire inter-site ne passe pas)');

        $this->client->request('POST', '/api/connexion', content: '{"email":"a@b.c","motDePasse":"x"}', server: ['CONTENT_TYPE' => 'text/plain']);
        self::assertSame(415, $this->etat()['statut'], 'text/plain est le type d\'une requête inter-site sans préliminaire CORS');
    }

    public function testMotDePasseDemesureRefuseSansCalcul(): void
    {
        $client = $this->creerClient();

        $debut = microtime(true);
        $this->json('POST', '/api/connexion', ['email' => $client['email'], 'motDePasse' => str_repeat('A', 1_000_000)]);

        self::assertSame(422, $this->etat()['statut']);
        self::assertLessThan(2.0, microtime(true) - $debut, 'pas de déni de service par hachage d\'un mot de passe géant');
    }

    public function testInscriptionMotDePasseDemesure(): void
    {
        $this->json('POST', '/api/inscription', $this->inscription(['motDePasse' => str_repeat('é', 129)]));

        self::assertSame(422, $this->etat()['statut']);
        self::assertSame('Le mot de passe ne doit pas dépasser 128 caractères.', $this->etat()['corps']['erreurs']['motDePasse']);
    }

    public function testMassAssignmentLeClientNeChoisitNiSonRoleNiSonStatut(): void
    {
        $email = 'auth-test-mass-'.bin2hex(random_bytes(3)).'@example.test';

        $this->json('POST', '/api/inscription', $this->inscription([
            'email' => $email, 'roles' => ['ADMIN'], 'role' => 'ADMIN', 'actif' => true, 'emailVerifie' => true,
            'a2fActive' => true, 'id' => '00000000-0000-4000-8000-000000000001', 'motDePasseHash' => 'x',
        ]));

        self::assertSame(201, $this->etat()['statut']);
        $ligne = $this->connexion->fetchAssociative(
            "SELECT u.id::text, u.email_verifie, u.a2f_active, string_agg(ur.role_code, ',') AS roles FROM utilisateur u JOIN utilisateur_role ur ON ur.utilisateur_id = u.id WHERE u.email = :e GROUP BY u.id",
            ['e' => $email],
        );
        self::assertIsArray($ligne);
        self::assertSame('CLIENT', $ligne['roles'], 'rôle toujours CLIENT');
        self::assertFalse($ligne['email_verifie'], 'e-mail non vérifié à la création');
        self::assertFalse($ligne['a2f_active']);
        self::assertNotSame('00000000-0000-4000-8000-000000000001', $ligne['id'], 'identifiant généré par la base');
        self::assertSame(['CLIENT'], $this->etat()['corps']['utilisateur']['roles']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function charges(): iterable
    {
        yield 'script' => ['prenom', '<script>alert(1)</script>'];
        yield 'balise img' => ['nom', '<img src=x onerror=alert(1)>'];
        yield 'chevron seul' => ['prenom', 'Ana>'];
        yield 'octet nul' => ['nom', "Roy\0"];
        yield 'saut de ligne' => ['prenom', "Ana\nRoy"];
        yield 'trop long' => ['nom', str_repeat('a', 81)];
    }

    #[DataProvider('charges')]
    public function testNomsEtPrenomsHostilesRefusesAvantLaBase(string $champ, string $valeur): void
    {
        $email = 'auth-test-xss-'.bin2hex(random_bytes(3)).'@example.test';

        $this->json('POST', '/api/inscription', $this->inscription(['email' => $email, $champ => $valeur]));

        self::assertSame(422, $this->etat()['statut']);
        self::assertArrayHasKey($champ, $this->etat()['corps']['erreurs']);
        self::assertSame(0, (int) $this->connexion->fetchOne('SELECT count(*) FROM utilisateur WHERE email = :e', ['e' => $email]), 'rien n\'est écrit');
    }

    public function testApostropheEtAccentsRestentAcceptes(): void
    {
        $email = 'auth-test-accents-'.bin2hex(random_bytes(3)).'@example.test';

        $this->json('POST', '/api/inscription', $this->inscription(['email' => $email, 'prenom' => 'Zoé', 'nom' => "O'Brien-Müller"]));

        self::assertSame(201, $this->etat()['statut'], 'valider n\'est pas appauvrir : « O\'Brien » est un nom valide');
    }

    /** @return iterable<string, array{string}> */
    public static function emailsInvalides(): iterable
    {
        yield 'sans arobase' => ['pas-un-email'];
        yield 'sans domaine' => ['a@'];
        yield 'espaces' => ['a b@example.test'];
        yield 'deux arobases' => ['a@b@example.test'];
        yield 'trop long' => [str_repeat('a', 250).'@b.fr'];
        yield 'injection d\'en-tête' => ["a@example.test\r\nBcc: victime@example.test"];
    }

    #[DataProvider('emailsInvalides')]
    public function testEmailInvalideRefuse(string $email): void
    {
        $this->json('POST', '/api/inscription', $this->inscription(['email' => $email]));

        self::assertSame(422, $this->etat()['statut']);
        self::assertArrayHasKey('email', $this->etat()['corps']['erreurs']);
    }

    public function testPasDEnumerationDeComptesParLaConnexion(): void
    {
        $client = $this->creerClient();

        $inconnu = $this->connecter('inconnu-'.bin2hex(random_bytes(3)).'@example.test', 'un mot de passe quelconque');
        $existant = $this->connecter($client['email'], 'un mauvais mot de passe');
        $desactive = $this->connecter('client15@sidekey.test', self::MOT_DE_PASSE_JEU_ESSAI);

        self::assertSame(401, $inconnu['statut']);
        self::assertSame($inconnu['statut'], $existant['statut']);
        self::assertSame($inconnu['statut'], $desactive['statut']);
        self::assertSame($inconnu['corps'], $existant['corps'], 'même message pour un e-mail inconnu et un mauvais mot de passe');
        self::assertSame($inconnu['corps'], $desactive['corps'], 'et pour un compte désactivé');
    }

    public function testLeTempsDeReponseNeRevelePasQuUnCompteExiste(): void
    {
        // Un hachage est toujours vérifié, même pour un e-mail inconnu : l'écart de durée reste faible
        // devant le coût d'un hachage (comparaison de médianes pour absorber le bruit).
        $client = $this->creerClient();
        $existants = $inconnus = [];
        for ($i = 0; $i < 5; ++$i) {
            $t = microtime(true);
            $this->connecter($client['email'], 'un mauvais mot de passe');
            $existants[] = microtime(true) - $t;
            $t = microtime(true);
            $this->connecter('inconnu'.$i.'@example.test', 'un mauvais mot de passe');
            $inconnus[] = microtime(true) - $t;
        }
        sort($existants);
        sort($inconnus);

        // Sans le hachage factice, un e-mail inconnu répondrait en quelques ms contre ~dizaines de ms ici.
        self::assertGreaterThan($existants[2] * 0.4, $inconnus[2], 'médiane inconnue / médiane existante : même ordre de grandeur');
    }

    public function testLInscriptionNeRevelePasLeHashNiLeMotDePasse(): void
    {
        $email = 'auth-test-fuite-'.bin2hex(random_bytes(3)).'@example.test';

        $this->json('POST', '/api/inscription', $this->inscription(['email' => $email]));
        $contenu = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString(self::MOT_DE_PASSE, $contenu);
        self::assertStringNotContainsString('argon2', $contenu);
        self::assertStringNotContainsString('mot_de_passe', $contenu);
    }

    public function testInscriptionLimiteeParAdresse(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->json('POST', '/api/inscription', $this->inscription(['email' => 'auth-test-rafale-'.$i.'-'.bin2hex(random_bytes(2)).'@example.test']));
            self::assertSame(201, $this->etat()['statut']);
        }

        $this->json('POST', '/api/inscription', $this->inscription(['email' => 'auth-test-rafale-6@example.test']));

        self::assertSame(429, $this->etat()['statut'], 'rl:register:{ip} : 5 comptes par heure et par adresse');
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM utilisateur WHERE email = 'auth-test-rafale-6@example.test'"));
    }

    public function testEnTeteXForwardedForNePermetPasDeContournerLaLimitation(): void
    {
        // Sans proxy de confiance configuré, X-Forwarded-For est ignoré : l'attaquant ne change pas d'adresse à volonté.
        $client = $this->creerClient();
        for ($i = 1; $i <= 10; ++$i) {
            $this->json('POST', '/api/connexion', ['email' => $client['email'], 'motDePasse' => 'faux '.$i], serveur: ['HTTP_X_FORWARDED_FOR' => '203.0.113.'.$i]);
            self::assertSame(401, $this->etat()['statut']);
        }

        $this->json('POST', '/api/connexion', ['email' => $client['email'], 'motDePasse' => 'faux 11'], serveur: ['HTTP_X_FORWARDED_FOR' => '203.0.113.99']);

        self::assertSame(429, $this->etat()['statut']);
    }

    public function testUneErreurInterneNeFuitePasDeDetailTechnique(): void
    {
        // Un jeton dont la charge casse le décodage ne doit jamais produire une trace dans la réponse.
        $this->client->request('GET', '/api/moi', server: ['HTTP_AUTHORIZATION' => 'Bearer '.str_repeat('é', 5000)]);
        $contenu = (string) $this->client->getResponse()->getContent();

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        foreach (['Exception', 'vendor/', '/var/www', 'Stack trace', 'SQLSTATE', 'Lexik'] as $fuite) {
            self::assertStringNotContainsString($fuite, $contenu);
        }
    }

    /**
     * @param array<string, mixed> $surcharge
     *
     * @return array<string, mixed>
     */
    private function inscription(array $surcharge = []): array
    {
        return $surcharge + [
            'email' => 'auth-test-'.bin2hex(random_bytes(4)).'@example.test',
            'motDePasse' => self::MOT_DE_PASSE,
            'prenom' => 'Ines',
            'nom' => 'Roy',
            'langue' => 'fr',
            'consentement' => true,
        ];
    }
}
