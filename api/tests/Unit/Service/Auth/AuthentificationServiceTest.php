<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Exception\IdentifiantsInvalidesException;
use App\Exception\TropDeTentativesException;
use App\Security\Utilisateur;
use App\Service\Auth\AuthentificationService;
use App\Service\Auth\SessionService;
use App\Tests\Support\Auth\ComptesEnMemoire;
use App\Tests\Support\Auth\EmetteurFactice;
use App\Tests\Support\Auth\FabriqueCompte;
use App\Tests\Support\Auth\LimiteurEnMemoire;
use App\Tests\Support\Auth\RevocationEnMemoire;
use App\Tests\Support\Auth\StockRefreshEnMemoire;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;

final class AuthentificationServiceTest extends TestCase
{
    private ComptesEnMemoire $comptes;
    private LimiteurEnMemoire $limiteur;
    private StockRefreshEnMemoire $refresh;
    private AuthentificationService $service;

    protected function setUp(): void
    {
        $this->comptes = new ComptesEnMemoire();
        $this->limiteur = new LimiteurEnMemoire();
        $this->refresh = new StockRefreshEnMemoire();
        $sessions = new SessionService($this->refresh, new RevocationEnMemoire(), new EmetteurFactice(), $this->comptes, new MockClock(), new NullLogger());
        $this->service = new AuthentificationService($this->comptes, $this->limiteur, FabriqueCompte::hashers(), $sessions);
    }

    public function testConnexionReussieOuvreUneSession(): void
    {
        $compte = $this->comptes->ajouter(FabriqueCompte::creer(1));

        $resultat = $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');

        self::assertFalse($resultat->exigeA2f());
        self::assertNotNull($resultat->session);
        $acces = EmetteurFactice::lire($resultat->session->jetonAcces);
        self::assertSame(['access', $compte->id, SessionService::DUREE_ACCES], [$acces['type'], $acces['compte'], $acces['duree']]);
        self::assertSame(900, $resultat->session->expireDans, 'jeton d\'accès de 15 minutes');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $resultat->session->jetonRefresh);
        self::assertCount(1, $this->refresh->actifs, 'le refresh est enregistré (empreinte seulement)');
        self::assertArrayNotHasKey($resultat->session->jetonRefresh, $this->refresh->actifs, 'jamais le jeton en clair');
    }

    public function testEmailInsensibleALaCasseEtAuxEspaces(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1));

        $resultat = $this->service->connecter('  COMPTE1@Sidekey.TEST ', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');

        self::assertNotNull($resultat->session);
    }

    public function testMauvaisMotDePasse(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1));

        $this->expectException(IdentifiantsInvalidesException::class);
        $this->service->connecter('compte1@sidekey.test', 'mauvais mot de passe', '203.0.113.7');
    }

    public function testEmailInconnuRenvoieLaMemeErreur(): void
    {
        $this->expectException(IdentifiantsInvalidesException::class);
        $this->service->connecter('inconnu@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');
    }

    public function testCompteDesactiveRefuseMemeAvecLeBonMotDePasse(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1, actif: false));

        $this->expectException(IdentifiantsInvalidesException::class);
        $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');
    }

    public function testMotDePasseGeantRefuseSansEtreHache(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1));

        $debut = microtime(true);
        try {
            $this->service->connecter('compte1@sidekey.test', str_repeat('x', 100_000), '203.0.113.7');
            self::fail('Un mot de passe de 100 000 caractères doit être refusé.');
        } catch (IdentifiantsInvalidesException) {
            self::assertLessThan(1.0, microtime(true) - $debut, 'refus immédiat : pas de déni de service par hachage');
        }
    }

    public function testLimitationDeDebitParAdresse(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1));

        for ($i = 0; $i < AuthentificationService::TENTATIVES_MAX; ++$i) {
            try {
                $this->service->connecter('compte1@sidekey.test', 'mauvais mot de passe', '203.0.113.7');
            } catch (IdentifiantsInvalidesException) {
            }
        }

        try {
            // La 11e tentative est bloquée AVANT toute vérification, même avec le bon mot de passe.
            $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');
            self::fail('La limitation de débit aurait dû s\'appliquer.');
        } catch (TropDeTentativesException $e) {
            self::assertSame(429, $e->codeHttp());
            self::assertSame(['Retry-After' => '900'], $e->entetes());
        }

        // Une autre adresse n'est pas affectée.
        self::assertNotNull($this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.8')->session);
    }

    public function testLaCleDeLimitationNeContientQuUneAdresseValide(): void
    {
        try {
            $this->service->connecter('x@sidekey.test', 'x', "1.2.3.4\r\nSET pirate 1");
        } catch (IdentifiantsInvalidesException) {
        }

        self::assertSame(['rl:login:inconnue'], array_keys($this->limiteur->compteurs), 'une valeur forgée n\'entre jamais dans une clé Redis');
    }

    public function testAfaActifeDonneUnJetonIntermediaireSansSession(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1, a2f: true));

        $resultat = $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');

        self::assertTrue($resultat->exigeA2f());
        self::assertNull($resultat->session, 'ni jeton d\'accès ni refresh avant le code TOTP');
        $intermediaire = EmetteurFactice::lire((string) $resultat->jetonIntermediaire);
        self::assertSame('a2f', $intermediaire['type'], 'portée limitée : jamais « access »');
        self::assertSame(300, $intermediaire['duree']);
        self::assertSame([], $this->refresh->actifs, 'aucun refresh n\'existe tant que l\'A2F n\'est pas validée');
    }

    /** @return iterable<string, array{string}> */
    public static function rolesDuBackOffice(): iterable
    {
        yield 'gestionnaire' => ['GESTIONNAIRE'];
        yield 'modérateur' => ['MODERATEUR'];
        yield 'administrateur' => ['ADMIN'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rolesDuBackOffice')]
    public function testUnRoleDuBackOfficeSansA2fNObtientQueL_Enrolement(string $role): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1, [$role], a2f: false));

        $resultat = $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');

        self::assertTrue($resultat->exigeA2f());
        self::assertTrue($resultat->enrolement, "A2F obligatoire au back-office : la seule suite est l'enrôlement");
        self::assertNull($resultat->session, 'aucune session, aucun refresh, sans second facteur');
        self::assertSame('a2f', EmetteurFactice::lire((string) $resultat->jetonIntermediaire)['type']);
        self::assertSame([], $this->refresh->actifs);
    }

    public function testUnCompteAvecA2fActiveNEstPasEnEnrolement(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1, ['ADMIN'], a2f: true));

        $resultat = $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');

        self::assertTrue($resultat->exigeA2f());
        self::assertFalse($resultat->enrolement, 'étape « code »');
    }

    public function testUnClientSansA2fSeConnecteSansSecondeEtape(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1, ['CLIENT']));

        $resultat = $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');

        self::assertFalse($resultat->exigeA2f(), 'A2F facultative pour les clients (BX-07)');
        self::assertFalse(EmetteurFactice::lire((string) $resultat->session?->jetonAcces)['a2f'], 'sans revendication a2f');
    }

    public function testUnCompteMultiRoleAvecUnRoleDuBackOfficeEstEnrole(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1, ['CLIENT', 'MODERATEUR']));

        self::assertTrue($this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7')->enrolement);
    }

    public function testUnHashBcryptEstRemplaceParDeLArgon2idALaConnexion(): void
    {
        // Les comptes du jeu d'essai sont en bcrypt : ils migrent à la première connexion.
        $bcrypt = (new PasswordHasherFactory([Utilisateur::class => ['algorithm' => 'bcrypt', 'cost' => 4]]))
            ->getPasswordHasher(Utilisateur::class)->hash(FabriqueCompte::MOT_DE_PASSE);
        self::assertStringStartsWith('$2y$', $bcrypt);
        $this->comptes->ajouter(FabriqueCompte::creer(1, hash: $bcrypt));

        $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');

        $nouveau = $this->comptes->parId(FabriqueCompte::uuid(1))?->motDePasseHash;
        self::assertStringStartsWith('$argon2id$', (string) $nouveau);
        // Et la connexion suivante fonctionne avec le nouveau hash, sans nouveau remplacement.
        $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');
        self::assertNull($this->comptes->connexions[1]['nouveauHash']);
    }

    public function testUnHashDejaArgon2idNEstPasRecalcule(): void
    {
        $this->comptes->ajouter(FabriqueCompte::creer(1));

        $this->service->connecter('compte1@sidekey.test', FabriqueCompte::MOT_DE_PASSE, '203.0.113.7');

        self::assertNull($this->comptes->connexions[0]['nouveauHash']);
    }
}
