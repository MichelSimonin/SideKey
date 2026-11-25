<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Panier;

use App\Exception\OffreIndisponibleException;
use App\Exception\PanierPleinException;
use App\Exception\QuantiteMaximaleException;
use App\Exception\StockInsuffisantException;
use App\Service\Panier\PanierId;
use App\Service\Panier\PanierService;
use App\Tests\Support\CatalogueOffresEnMemoire;
use App\Tests\Support\PanierStoreEnMemoire;
use PHPUnit\Framework\TestCase;

final class PanierServiceTest extends TestCase
{
    private const A = '11111111-1111-4111-8111-111111111111';
    private const B = '22222222-2222-4222-8222-222222222222';

    private PanierStoreEnMemoire $store;
    private CatalogueOffresEnMemoire $catalogue;
    private PanierService $service;
    private PanierId $panier;

    protected function setUp(): void
    {
        $this->store = new PanierStoreEnMemoire();
        $this->catalogue = new CatalogueOffresEnMemoire();
        $this->catalogue->ajouter(self::A, 2999, 12);
        $this->catalogue->ajouter(self::B, 1050, 2);
        $this->service = new PanierService($this->store, $this->catalogue);
        $this->panier = PanierId::visiteur('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
    }

    public function testAjoutCalculeLeTotalCoteServeur(): void
    {
        $this->service->ajouter($this->panier, self::A, 2);
        $panier = $this->service->ajouter($this->panier, self::B, 1);

        self::assertSame(2 * 2999 + 1050, $panier->totalCentimes());
        self::assertSame(3, $panier->nombreArticles());
        self::assertCount(2, $panier->lignes);
    }

    public function testAjouterDeuxFoisCumuleLesQuantites(): void
    {
        $this->service->ajouter($this->panier, self::A, 2);
        $panier = $this->service->ajouter($this->panier, self::A, 1);

        self::assertSame(3, $panier->lignes[0]->quantite);
    }

    public function testCinqClesAuMaximumParOffre(): void
    {
        $this->service->ajouter($this->panier, self::A, 5);

        $this->expectException(QuantiteMaximaleException::class);
        $this->service->ajouter($this->panier, self::A, 1);
    }

    public function testQuantiteBorneeParLeStock(): void
    {
        try {
            $this->service->ajouter($this->panier, self::B, 3);
            self::fail('Le stock (2 clés) aurait dû être dépassé');
        } catch (StockInsuffisantException $e) {
            self::assertSame(2, $e->disponible());
        }
        self::assertSame([], $this->store->lire($this->panier), 'rien n\'est écrit en cas de refus');
    }

    public function testOffreInconnueOuInactiveRefusee(): void
    {
        $this->catalogue->ajouter('33333333-3333-4333-8333-333333333333', 500, 10, active: false);

        $this->expectException(OffreIndisponibleException::class);
        $this->service->ajouter($this->panier, '33333333-3333-4333-8333-333333333333', 1);
    }

    public function testModifierFixeLaQuantiteEtZeroRetire(): void
    {
        $this->service->ajouter($this->panier, self::A, 1);

        self::assertSame(4, $this->service->modifier($this->panier, self::A, 4)->lignes[0]->quantite);
        self::assertSame([], $this->service->modifier($this->panier, self::A, 0)->lignes);
    }

    public function testVingtOffresAuMaximum(): void
    {
        for ($i = 0; $i < PanierService::OFFRES_MAX; ++$i) {
            $id = \sprintf('00000000-0000-4000-8000-%012d', $i);
            $this->catalogue->ajouter($id, 100, 5);
            $this->service->ajouter($this->panier, $id, 1);
        }
        $this->catalogue->ajouter('99999999-9999-4999-8999-999999999999', 100, 5);

        $this->expectException(PanierPleinException::class);
        $this->service->ajouter($this->panier, '99999999-9999-4999-8999-999999999999', 1);
    }

    public function testConsultationAjusteAuStockEtRetireLesOffresIndisponibles(): void
    {
        $this->service->ajouter($this->panier, self::A, 4);
        $this->service->ajouter($this->panier, self::B, 2);
        // Entre-temps : le stock de A tombe à 3, B est retirée de la vente
        $this->catalogue->ajouter(self::A, 2999, 3);
        $this->catalogue->ajouter(self::B, 1050, 2, active: false);

        $panier = $this->service->consulter($this->panier);

        self::assertCount(1, $panier->lignes);
        self::assertSame(3, $panier->lignes[0]->quantite);
        self::assertTrue($panier->lignes[0]->quantiteAjustee);
        self::assertSame([self::B], $panier->offresRetirees);
        self::assertSame([self::A => 3], $this->store->lire($this->panier), 'l\'ajustement est enregistré');
    }

    public function testOffreEnRuptureRetireeALaConsultation(): void
    {
        $this->service->ajouter($this->panier, self::B, 1);
        $this->catalogue->ajouter(self::B, 1050, 0);

        $panier = $this->service->consulter($this->panier);

        self::assertSame([], $panier->lignes);
        self::assertSame([self::B], $panier->offresRetirees);
    }

    public function testPrixToujoursRelusEnBase(): void
    {
        $this->service->ajouter($this->panier, self::A, 1);
        $this->catalogue->ajouter(self::A, 1999, 12);   // baisse de prix

        self::assertSame(1999, $this->service->consulter($this->panier)->totalCentimes());
    }
}
