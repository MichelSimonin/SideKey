<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Commande;

use App\Exception\CommandeEnCoursException;
use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonAnnulableException;
use App\Exception\CompteNonVerifieException;
use App\Exception\MajoriteNonConfirmeeException;
use App\Exception\OffreIndisponibleException;
use App\Exception\PanierModifieException;
use App\Exception\PanierVideException;
use App\Exception\StockInsuffisantException;
use App\Service\Auth\Compte;
use App\Service\Catalogue\Langue;
use App\Service\Commande\CommandeService;
use App\Service\Panier\PanierId;
use App\Service\Panier\PanierService;
use App\Service\Reservation\MinuteurReservation;
use App\Tests\Support\Catalogue\CacheEnMemoire;
use App\Tests\Support\CatalogueOffresEnMemoire;
use App\Tests\Support\Commande\CommandesEnMemoire;
use App\Tests\Support\Commande\MinuteurEnMemoire;
use App\Tests\Support\PanierStoreEnMemoire;
use PHPUnit\Framework\TestCase;

/** US-30, US-31, US-35 : règles avant écriture, effets après validation, annulation. */
final class CommandeServiceTest extends TestCase
{
    private const OFFRE = '0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
    private const OFFRE_18 = '9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a';
    private const PANIER = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    private CommandesEnMemoire $commandes;
    private PanierStoreEnMemoire $store;
    private CatalogueOffresEnMemoire $catalogue;
    private MinuteurEnMemoire $minuteur;
    private CacheEnMemoire $cache;
    private CommandeService $service;

    protected function setUp(): void
    {
        $this->commandes = new CommandesEnMemoire();
        $this->store = new PanierStoreEnMemoire();
        $this->catalogue = new CatalogueOffresEnMemoire();
        $this->minuteur = new MinuteurEnMemoire();
        $this->cache = new CacheEnMemoire();
        foreach ([[self::OFFRE, 1999, 12], [self::OFFRE_18, 4999, 18]] as [$id, $prix, $pegi]) {
            $this->commandes->ajouterOffre($id, $prix, 3, $pegi);
            $this->catalogue->ajouter($id, $prix, 3);
        }
        $this->service = new CommandeService($this->commandes, new PanierService($this->store, $this->catalogue), $this->minuteur, $this->cache);
    }

    public function testPasserCommandeReserveVideLePanierEtDemarreLeMinuteur(): void
    {
        $this->remplir([self::OFFRE => 2]);
        $version = $this->cache->version;

        $commande = $this->service->passer(self::compte(), $this->panier(), false, Langue::En);

        self::assertSame('EN_ATTENTE_PAIEMENT', $commande->statut);
        self::assertSame(3998, $commande->montantCentimes, 'montant relu en base, pas envoyé par le client');
        self::assertSame(1, $this->commandes->stock[self::OFFRE], '2 clés réservées sur 3');
        self::assertSame(Langue::En, array_values($this->commandes->commandes)[0]['langue'], 'langue de la commande = langue de la requête');
        self::assertSame([], $this->store->lire($this->panier()), 'panier vidé après validation');
        self::assertArrayHasKey($commande->id, $this->minuteur->minuteurs);
        self::assertGreaterThan(MinuteurReservation::DUREE_SECONDES - 5, $commande->secondesRestantes);
        self::assertSame($version + 1, $this->cache->version, 'listes du catalogue invalidées : le stock a changé');
    }

    public function testCompteNonVerifie(): void
    {
        $this->remplir([self::OFFRE => 1]);

        $this->expectException(CompteNonVerifieException::class);
        $this->service->passer(self::compte(emailVerifie: false), $this->panier(), false, Langue::Fr);
    }

    public function testSansPanierOuPanierVide(): void
    {
        try {
            $this->service->passer(self::compte(), null, false, Langue::Fr);
            self::fail('exception attendue');
        } catch (PanierVideException) {
        }
        $this->expectException(PanierVideException::class);
        $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);
    }

    public function testPanierAjusteAuStockLeClientDoitLeRevoir(): void
    {
        $this->remplir([self::OFFRE => 3]);
        $this->catalogue->ajouter(self::OFFRE, 1999, 2);   // une clé vendue entre-temps

        try {
            $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);
            self::fail('exception attendue');
        } catch (PanierModifieException $e) {
            self::assertSame(409, $e->codeHttp());
        }
        self::assertSame([], $this->commandes->commandes, 'aucune commande créée');
        self::assertSame([self::OFFRE => 2], $this->store->lire($this->panier()), 'le panier montre la quantité possible');
    }

    public function testOffreRetireeDuPanierLeClientDoitLeRevoir(): void
    {
        $this->remplir([self::OFFRE => 1]);
        $this->catalogue->ajouter(self::OFFRE, 1999, 3, active: false);

        $this->expectException(PanierModifieException::class);
        $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);
    }

    public function testPegi18SansDeclarationDeMajorite(): void
    {
        $this->remplir([self::OFFRE => 1, self::OFFRE_18 => 1]);

        try {
            $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);
            self::fail('exception attendue');
        } catch (MajoriteNonConfirmeeException $e) {
            self::assertSame(422, $e->codeHttp());
        }
        self::assertSame([], $this->commandes->commandes);
        self::assertNotSame([], $this->store->lire($this->panier()), 'le panier est conservé');

        $commande = $this->service->passer(self::compte(), $this->panier(), true, Langue::Fr);
        self::assertSame(6998, $commande->montantCentimes);
        self::assertTrue(array_values($this->commandes->commandes)[0]['majeur']);
    }

    public function testOffreDesactiveeEntreLePanierEtLaCommande(): void
    {
        $this->remplir([self::OFFRE => 1]);
        $this->commandes->ajouterOffre(self::OFFRE, 1999, 3, active: false);

        $this->expectException(OffreIndisponibleException::class);
        $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);
    }

    public function testStockInsuffisantAuMomentDeReserverRienNEstReserveLePanierReste(): void
    {
        $this->remplir([self::OFFRE => 1, self::OFFRE_18 => 2]);
        $this->commandes->stock[self::OFFRE_18] = 1;   // acheté par un autre client pendant la validation

        try {
            $this->service->passer(self::compte(), $this->panier(), true, Langue::Fr);
            self::fail('exception attendue');
        } catch (StockInsuffisantException $e) {
            self::assertSame(['disponible' => 1], $e->details());
        }
        self::assertSame(3, $this->commandes->stock[self::OFFRE], 'tout ou rien : la première ligne n\'est pas réservée');
        self::assertNotSame([], $this->store->lire($this->panier()));
        self::assertSame([], $this->minuteur->minuteurs);
    }

    public function testUneSeuleCommandeEnAttenteParClient(): void
    {
        $this->remplir([self::OFFRE => 1]);
        $premiere = $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);
        $this->remplir([self::OFFRE => 1]);

        try {
            $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);
            self::fail('exception attendue');
        } catch (CommandeEnCoursException $e) {
            self::assertSame(['reference' => $premiere->reference], $e->details());
        }
    }

    public function testRedisEnPanneApresValidationLaCommandeResteValable(): void
    {
        $this->remplir([self::OFFRE => 1]);
        $this->minuteur->enPanne = true;

        $commande = $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);

        self::assertSame('EN_ATTENTE_PAIEMENT', $commande->statut);
        self::assertGreaterThan(0, $commande->secondesRestantes, 'à défaut de Redis, l\'échéance en base fait foi');
    }

    public function testLaCommandeDUnAutreClientEstIntrouvable(): void
    {
        $this->remplir([self::OFFRE => 1]);
        $commande = $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);

        $this->expectException(CommandeIntrouvableException::class);
        $this->service->consulter($commande->reference, 'un-autre-client');
    }

    public function testAnnulerRemetEnStockEtArreteLeMinuteur(): void
    {
        $this->remplir([self::OFFRE => 2]);
        $commande = $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);

        $annulee = $this->service->annuler($commande->reference, self::compte()->id);

        self::assertSame('ANNULEE', $annulee->statut);
        self::assertNull($annulee->secondesRestantes);
        self::assertSame(3, $this->commandes->stock[self::OFFRE]);
        self::assertSame([], $this->minuteur->minuteurs);

        $this->expectException(CommandeNonAnnulableException::class);
        $this->service->annuler($commande->reference, self::compte()->id);
    }

    public function testAnnulerLaCommandeDAutrui(): void
    {
        $this->remplir([self::OFFRE => 1]);
        $commande = $this->service->passer(self::compte(), $this->panier(), false, Langue::Fr);

        $this->expectException(CommandeIntrouvableException::class);
        $this->service->annuler($commande->reference, 'un-autre-client');
    }

    /** @param array<string, int> $contenu */
    private function remplir(array $contenu): void
    {
        foreach ($contenu as $offre => $quantite) {
            $this->store->definir($this->panier(), $offre, $quantite);
        }
    }

    private function panier(): PanierId
    {
        return PanierId::visiteur(self::PANIER);
    }

    private static function compte(bool $emailVerifie = true): Compte
    {
        return new Compte('11111111-2222-4333-8444-555555555555', 'lea@example.test', 'hash', 'Léa', 'Martin', 'fr', ['CLIENT'], false, true, $emailVerifie);
    }
}
