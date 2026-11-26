<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\Auth\JwtForge;
use App\Tests\Support\Catalogue\CatalogueAdminWebTestCase;
use App\Tests\Support\Catalogue\FabriqueWebp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Sécurité du back-office catalogue (F-09), un test par menace :
 *  - contrôle d'accès : 401 sans jeton, 403 pour CLIENT et MODERATEUR, 403 sans second facteur, sur TOUTES les routes ;
 *  - upload : type lu dans le contenu, taille bornée, nom régénéré, aucun fichier écrit en cas de refus ;
 *  - affectation de masse, injection SQL, XSS stocké, identifiants forgés, fuite d'information dans les erreurs.
 */
#[Group('database')]
#[Group('redis')]
final class CatalogueAdminSecuriteTest extends CatalogueAdminWebTestCase
{
    private const AUCUN = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected static function adresseIp(): string
    {
        return '198.51.100.91';
    }

    /**
     * Toutes les routes du back-office catalogue, avec un corps plausible : une route oubliée dans la matrice serait une
     * route non testée.
     *
     * @return iterable<string, array{string, string, array<string, mixed>|null}>
     */
    public static function routes(): iterable
    {
        $corpsJeu = ['titre' => 'ZZ Test', 'pegi' => 16, 'editeurId' => self::AUCUN, 'descriptions' => ['fr' => 'Description de test.', 'en' => 'Test description.']];
        yield 'GET editeurs' => ['GET', '/api/admin/editeurs', null];
        yield 'POST editeurs' => ['POST', '/api/admin/editeurs', ['nom' => 'ZZ Test Intrus']];
        yield 'PUT editeur' => ['PUT', '/api/admin/editeurs/'.self::AUCUN, ['nom' => 'ZZ Test Intrus']];
        yield 'DELETE editeur' => ['DELETE', '/api/admin/editeurs/'.self::AUCUN, null];
        yield 'GET plateformes' => ['GET', '/api/admin/plateformes', null];
        yield 'POST plateformes' => ['POST', '/api/admin/plateformes', ['code' => 'ZZTEST', 'libelle' => 'Intrus']];
        yield 'PUT plateforme' => ['PUT', '/api/admin/plateformes/'.self::AUCUN, ['libelle' => 'Intrus']];
        yield 'DELETE plateforme' => ['DELETE', '/api/admin/plateformes/'.self::AUCUN, null];
        yield 'GET genres' => ['GET', '/api/admin/genres', null];
        yield 'GET jeux' => ['GET', '/api/admin/jeux', null];
        yield 'GET jeu' => ['GET', '/api/admin/jeux/'.self::AUCUN, null];
        yield 'POST jeux' => ['POST', '/api/admin/jeux', $corpsJeu + ['slug' => 'zz-test-intrus']];
        yield 'PUT jeu' => ['PUT', '/api/admin/jeux/'.self::AUCUN, $corpsJeu];
        yield 'DELETE jeu' => ['DELETE', '/api/admin/jeux/'.self::AUCUN, null];
        yield 'POST offre' => ['POST', '/api/admin/jeux/'.self::AUCUN.'/offres', ['plateformeId' => self::AUCUN, 'prix' => '1.00', 'seuilAlerte' => 1]];
        yield 'PUT offre' => ['PUT', '/api/admin/offres/'.self::AUCUN, ['prix' => '1.00', 'seuilAlerte' => 1, 'actif' => true, 'version' => 1]];
        yield 'POST jaquette' => ['POST', '/api/admin/jeux/'.self::AUCUN.'/jaquette', null];
        yield 'DELETE jaquette' => ['DELETE', '/api/admin/jeux/'.self::AUCUN.'/jaquette', null];
    }

    /** @param array<string, mixed>|null $corps */
    #[DataProvider('routes')]
    public function testUnVisiteurSansJetonRecoit401(string $methode, string $uri, ?array $corps): void
    {
        $this->json($methode, $uri, $corps);

        self::assertSame(401, $this->etat()['statut']);
    }

    /** @param array<string, mixed>|null $corps */
    #[DataProvider('routes')]
    public function testUnJetonForgeEstRefuse(string $methode, string $uri, ?array $corps): void
    {
        $valide = $this->jetonGestionnaire();
        $charge = JwtForge::charge($valide);
        $charge['roles'] = ['ADMIN'];   // l'attaquant s'attribue un rôle : la signature ne correspond plus

        foreach ([JwtForge::chargeRemplacee($valide, $charge), JwtForge::signatureAlteree($valide), JwtForge::sansSignature($charge), 'n-importe-quoi'] as $jeton) {
            $this->json($methode, $uri, $corps, $jeton);
            self::assertSame(401, $this->etat()['statut']);
        }
    }

    /** @param array<string, mixed>|null $corps */
    #[DataProvider('routes')]
    public function testUnClientEtUnModerateurRecoivent403(string $methode, string $uri, ?array $corps): void
    {
        foreach ([$this->jetonPour($this->creerClient()['email']), $this->jetonPour('moderateur@sidekey.test')] as $jeton) {
            $this->json($methode, $uri, $corps, $jeton);

            self::assertSame(403, $this->etat()['statut']);
            self::assertSame(['message' => 'Accès refusé.'], $this->etat()['corps'], 'refus générique : rien sur la règle ni sur l\'existence de la ressource');
        }
    }

    /** @param array<string, mixed>|null $corps */
    #[DataProvider('routes')]
    public function testSansSecondFacteurLeBackOfficeEstFerme(string $methode, string $uri, ?array $corps): void
    {
        foreach (['gestionnaire@sidekey.test', 'admin@sidekey.test'] as $email) {
            $this->json($methode, $uri, $corps, $this->jetonPour($email, a2fValide: false));

            self::assertSame(403, $this->etat()['statut'], $email.' sans A2F');
        }
    }

    /** @param array<string, mixed>|null $corps */
    #[DataProvider('routes')]
    public function testUnGestionnaireEtUnAdminAvecA2fPassentLeControleDAcces(string $methode, string $uri, ?array $corps): void
    {
        foreach (['gestionnaire@sidekey.test', 'admin@sidekey.test'] as $email) {
            $this->json($methode, $uri, $corps, $this->jetonPour($email));

            self::assertNotContains($this->etat()['statut'], [401, 403], $email.' avec A2F : l\'accès est accordé (l\'erreur éventuelle est métier)');
        }
    }

    public function testUnRefusNeModifieAucuneDonnee(): void
    {
        $avant = [
            (int) $this->connexion->fetchOne('SELECT count(*) FROM editeur'),
            (int) $this->connexion->fetchOne('SELECT count(*) FROM plateforme'),
            (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu'),
        ];
        $client = $this->jetonPour($this->creerClient()['email']);

        $this->json('POST', '/api/admin/editeurs', ['nom' => 'ZZ Test Intrus'], $client);
        $this->json('POST', '/api/admin/plateformes', ['code' => 'ZZTEST', 'libelle' => 'Intrus'], $client);
        $this->json('POST', '/api/admin/jeux', $this->corpsJeu($this->creerEditeur(), ['slug' => 'zz-test-intrus']), $client);

        self::assertSame($avant[0] + 1, (int) $this->connexion->fetchOne('SELECT count(*) FROM editeur'), 'seul l\'éditeur créé par le gestionnaire du test');
        self::assertSame($avant[1], (int) $this->connexion->fetchOne('SELECT count(*) FROM plateforme'));
        self::assertSame($avant[2], (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu'));
    }

    public function testLeCorpsDOneRequeteFormulaireEstRefuse(): void
    {
        // Seul le JSON est accepté : un formulaire HTML forgé depuis un autre site ne peut pas atteindre l'API.
        $this->client->request('POST', '/api/admin/editeurs', ['nom' => 'ZZ Test Formulaire'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jetonGestionnaire(), 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);

        self::assertContains($this->client->getResponse()->getStatusCode(), [415, 422, 400]);
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM editeur WHERE nom = 'ZZ Test Formulaire'"));
    }

    // ---- Upload ------------------------------------------------------------------------------------------------

    /** @return iterable<string, array{string, string, string, int}> contenu, nom client, type client, statut attendu */
    public static function televersementsHostiles(): iterable
    {
        $webp = FabriqueWebp::creer();
        $php = '<?php system($_GET["c"]); ?>';

        yield 'script PHP nommé .php' => [$php, 'shell.php', 'application/x-php', 422];
        yield 'script PHP déguisé en .webp avec le bon type' => [$php, 'jaquette.webp', 'image/webp', 422];
        yield 'script PHP à double extension' => [$php, 'jaquette.php.webp', 'image/webp', 422];
        yield 'script PHP derrière une signature WebP' => [substr($webp, 0, 12).$php, 'jaquette.webp', 'image/webp', 422];
        yield 'WebP suivi d\'un script' => [$webp.$php, 'jaquette.webp', 'image/webp', 422];
        yield 'SVG avec script' => ['<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>', 'jaquette.svg', 'image/svg+xml', 422];
        yield 'SVG déguisé en WebP' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'jaquette.webp', 'image/webp', 422];
        yield 'HTML déguisé en WebP' => ['<html><script>alert(1)</script></html>', 'jaquette.webp', 'image/webp', 422];
        yield 'PNG renommé en .webp' => ["\x89PNG\r\n\x1a\n".str_repeat("\x00", 64), 'jaquette.webp', 'image/webp', 422];
        yield 'JPEG' => ["\xff\xd8\xff\xe0".str_repeat("\x00", 64), 'jaquette.jpg', 'image/jpeg', 422];
        yield 'GIF' => ['GIF89a'.str_repeat("\x00", 64), 'jaquette.gif', 'image/gif', 422];
        yield 'WebP tronqué' => [substr($webp, 0, -4), 'jaquette.webp', 'image/webp', 422];
        yield 'WebP sans image' => ['RIFF'.pack('V', 36).'WEBP'.str_repeat("\x00", 32), 'jaquette.webp', 'image/webp', 422];
        yield 'trop lourde (600 Kio)' => [$webp.str_repeat('0', 600 * 1024), 'jaquette.webp', 'image/webp', 422];
        yield 'bombe de décompression (dimensions)' => [FabriqueWebp::creer(16_000, 16_000), 'jaquette.webp', 'image/webp', 422];
        yield 'vide' => ['', 'jaquette.webp', 'image/webp', 422];
    }

    #[DataProvider('televersementsHostiles')]
    public function testUnFichierHostileEstRefuseEtNEcritRien(string $contenu, string $nom, string $type, int $statut): void
    {
        $jeu = $this->creerJeu();

        $reponse = $this->televerser($jeu['id'], $contenu, $nom, $type);

        self::assertSame($statut, $reponse['statut']);
        self::assertSame([], $this->fichiersDeJaquettes(), 'aucun fichier écrit sur le disque');
        self::assertNull($this->api('GET', '/api/admin/jeux/'.$jeu['id'])['corps']['jaquette'], 'la base n\'a pas changé');
        self::assertStringNotContainsString('system(', (string) $this->client->getResponse()->getContent(), 'le contenu envoyé n\'est jamais renvoyé');
    }

    public function testLeNomEtLeTypeEnvoyesParLeClientNeComptentPas(): void
    {
        $jeu = $this->creerJeu();

        // Une vraie image WebP, déclarée comme n'importe quoi avec un nom de traversée de répertoire : acceptée, nom régénéré.
        $reponse = $this->televerser($jeu['id'], FabriqueWebp::creer(), '../../../public/index.php', 'text/x-php');

        self::assertSame(200, $reponse['statut'], 'le contenu fait foi, pas le nom ni le Content-Type');
        $fichiers = $this->fichiersDeJaquettes();
        self::assertCount(1, $fichiers);
        self::assertMatchesRegularExpression('#/jaquettes/[0-9a-f]{32}\.webp$#', $fichiers[0]);
        self::assertStringNotContainsString('index.php', $fichiers[0]);
        self::assertFileExists(\dirname(__DIR__, 2).'/public/index.php', 'le vrai point d\'entrée n\'a pas été touché');
        self::assertStringNotContainsString('index.php', (string) $reponse['corps']['jaquette']);
    }

    public function testLeChampDuFichierEstExige(): void
    {
        $jeu = $this->creerJeu();

        foreach (['fichier', 'image', 'file'] as $champ) {
            $reponse = $this->televerser($jeu['id'], FabriqueWebp::creer(), champ: $champ);
            self::assertSame(422, $reponse['statut'], $champ);
        }
        $this->json('POST', '/api/admin/jeux/'.$jeu['id'].'/jaquette', null, $this->jetonGestionnaire());
        self::assertSame(422, $this->etat()['statut'], 'requête sans fichier');
        self::assertSame([], $this->fichiersDeJaquettes());
    }

    public function testUnIdentifiantDeJeuForgeNeToucheAucunFichier(): void
    {
        foreach (['../../etc/passwd', "1' OR '1'='1", 'null', '%00', str_repeat('a', 300)] as $id) {
            $this->client->request('POST', '/api/admin/jeux/'.rawurlencode($id).'/jaquette', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jetonGestionnaire()]);

            self::assertContains($this->client->getResponse()->getStatusCode(), [404, 405], $id);
        }
        self::assertSame([], $this->fichiersDeJaquettes());
    }

    public function testUneJaquetteForgeeEnBaseNeSupprimeAucunAutreFichier(): void
    {
        // Défense en profondeur : même si la base contenait un chemin forgé, retirer la jaquette ne supprimerait rien d'autre.
        $jeu = $this->creerJeu();
        $voisin = \dirname(__DIR__, 2).'/var/cache/test/voisin-a-conserver.txt';
        file_put_contents($voisin, 'à conserver');
        $this->connexion->executeStatement("UPDATE jeu SET jaquette = '../voisin-a-conserver.txt' WHERE id = CAST(:id AS uuid)", ['id' => $jeu['id']]);

        self::assertSame(204, $this->api('DELETE', '/api/admin/jeux/'.$jeu['id'].'/jaquette')['statut']);

        self::assertFileExists($voisin);
        unlink($voisin);
    }

    // ---- Affectation de masse ----------------------------------------------------------------------------------

    public function testLesChampsNonListesSontIgnores(): void
    {
        $editeur = $this->creerEditeur();
        $reponse = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, [
            'id' => self::AUCUN,
            'jaquette' => '../../public/index.php',
            'creeLe' => '2000-01-01',
            'offres' => [['prix' => '0.01']],
            'version' => 99,
            'role' => 'ADMIN',
        ]));

        self::assertSame(201, $reponse['statut']);
        self::assertNotSame(self::AUCUN, $reponse['corps']['id'], 'l\'identifiant est généré par la base');
        self::assertNull($reponse['corps']['jaquette'], 'la jaquette ne se définit que par le téléversement contrôlé');
        self::assertSame([], $reponse['corps']['offres']);
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu WHERE jaquette LIKE '%..%'"));

        // Modification d'une offre : jeu, plateforme, version et stock ne se changent pas par le corps.
        $jeu = $reponse['corps'];
        $offre = $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => $this->plateformeExistante(), 'prix' => '10.00', 'seuilAlerte' => 1, 'jeuId' => self::AUCUN, 'version' => 50, 'actif' => false, 'stock' => 9999])['corps'];
        self::assertSame(1, $offre['version']);
        self::assertTrue($offre['actif'], 'une offre créée est toujours active');
        self::assertSame(0, $offre['stock'], 'le stock est le nombre de clés disponibles, jamais saisi');

        $modifiee = $this->api('PUT', '/api/admin/offres/'.$offre['id'], ['prix' => '11.00', 'seuilAlerte' => 1, 'actif' => true, 'version' => 1, 'jeuId' => self::AUCUN, 'plateformeId' => self::AUCUN, 'stock' => 9999]);
        self::assertSame(200, $modifiee['statut']);
        self::assertSame($this->plateformeExistante(), $modifiee['corps']['plateforme']['id']);
        self::assertSame(0, $modifiee['corps']['stock']);
        self::assertSame($jeu['id'], $this->api('GET', '/api/admin/jeux/'.$jeu['id'])['corps']['id']);
    }

    // ---- Injections --------------------------------------------------------------------------------------------

    public function testLesInjectionsSqlSontInertes(): void
    {
        $piege = "ZZ Test '); DROP TABLE jeu; --";
        $jeuxAvant = (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu');
        $editeur = $this->creerEditeur();

        $cree = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['titre' => $piege]));
        self::assertSame(201, $cree['statut']);
        self::assertSame($piege, $cree['corps']['titre'], 'stocké littéralement');

        foreach ([
            '/api/admin/jeux?q='.rawurlencode("' OR '1'='1"),
            '/api/admin/jeux?q='.rawurlencode("'; DELETE FROM jeu; --"),
            '/api/admin/jeux?q='.rawurlencode('%'),
            '/api/admin/jeux?statut='.rawurlencode("tous' OR 1=1 --"),
        ] as $uri) {
            $reponse = $this->api('GET', $uri);
            self::assertContains($reponse['statut'], [200, 422], $uri);
            self::assertStringNotContainsString('SQLSTATE', (string) $this->client->getResponse()->getContent());
        }
        self::assertSame($jeuxAvant + 1, (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu'), 'aucune ligne supprimée ni ajoutée par les injections');

        // Identifiants et slugs hostiles : refusés avant la base.
        foreach (["1' OR '1'='1", '1; DROP TABLE jeu', 'null'] as $id) {
            self::assertSame(404, $this->api('GET', '/api/admin/jeux/'.rawurlencode($id))['statut']);
        }
        self::assertSame(422, $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['slug' => "x'; DROP TABLE jeu; --"]))['statut']);
        self::assertSame(422, $this->api('POST', '/api/admin/jeux', $this->corpsJeu("$editeur' OR '1'='1", ['slug' => 'zz-test-injection']))['statut']);
        self::assertSame(422, $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['slug' => 'zz-test-injection', 'genres' => ["action' OR '1'='1"]]))['statut']);
    }

    public function testLeContenuHtmlEstRefuseCarIlSeraitAfficheAuxVisiteurs(): void
    {
        $editeur = $this->creerEditeur();
        $xss = '<img src=x onerror=alert(document.cookie)>';

        foreach ([
            $this->corpsJeu($editeur, ['titre' => 'ZZ Test '.$xss]),
            $this->corpsJeu($editeur, ['descriptions' => ['fr' => self::DESCRIPTION.' '.$xss, 'en' => self::DESCRIPTION]]),
            $this->corpsJeu($editeur, ['descriptions' => ['fr' => self::DESCRIPTION, 'en' => '<script>alert(1)</script> Description.']]),
        ] as $corps) {
            self::assertSame(422, $this->api('POST', '/api/admin/jeux', $corps)['statut']);
        }
        self::assertSame(422, $this->api('POST', '/api/admin/editeurs', ['nom' => 'ZZ Test '.$xss])['statut']);
        self::assertSame(422, $this->api('POST', '/api/admin/plateformes', ['code' => 'ZZTEST', 'libelle' => $xss])['statut']);
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu WHERE slug LIKE 'zz-test%'"));
    }

    // ---- Fuite d'information -----------------------------------------------------------------------------------

    public function testLesErreursNeRevelentNiSqlNiChemin(): void
    {
        $editeur = $this->creerEditeur();
        $this->creerJeu($editeur);

        $reponses = [
            $this->api('DELETE', '/api/admin/editeurs/'.$editeur),                                                     // clé étrangère
            $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur)),                                         // slug en double
            $this->api('POST', '/api/admin/plateformes', ['code' => 'zz', 'libelle' => 'x']),                          // code invalide
            $this->api('PUT', '/api/admin/offres/'.self::AUCUN, ['prix' => '1.00', 'seuilAlerte' => 1, 'actif' => true, 'version' => 1]),
            $this->api('GET', '/api/admin/jeux/'.self::AUCUN),
        ];
        foreach ($reponses as $reponse) {
            $texte = (string) json_encode($reponse['corps']);
            foreach (['SQLSTATE', 'sk_app', 'violates', 'constraint', 'uq_', 'fk_', 'ck_', '/var/www', '.php', 'Stack trace', 'Doctrine'] as $indice) {
                self::assertStringNotContainsStringIgnoringCase($indice, $texte, 'fuite « '.$indice.' » dans '.$texte);
            }
        }
    }
}
