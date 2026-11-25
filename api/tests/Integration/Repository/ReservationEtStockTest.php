<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Exception\CommandeNonReservableException;
use App\Exception\StockInsuffisantException;
use App\Repository\OffreDisponibiliteRepository;
use App\Repository\ReservationClesRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Accès SQL du panier et de la réservation, sur la base sidekey_test chargée avec le jeu d'essai.
 * Chaque test s'exécute dans une transaction annulée : la base n'est pas modifiée.
 */
#[Group('database')]
final class ReservationEtStockTest extends KernelTestCase
{
    private Connection $connexion;

    protected function setUp(): void
    {
        self::bootKernel();
        $connexion = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $this->connexion = $connexion;
        $this->connexion->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connexion->isTransactionActive()) {
            $this->connexion->rollBack();
        }
        parent::tearDown();
    }

    public function testStockEtPrixDesOffres(): void
    {
        $offre = $this->offreAvecStock(1, exact: true);
        $vide = $this->connexion->fetchOne(
            "SELECT o.id FROM offre o WHERE o.actif AND NOT EXISTS
               (SELECT 1 FROM cle_licence k WHERE k.offre_id = o.id AND k.statut = 'DISPONIBLE') LIMIT 1");

        $dispos = (new OffreDisponibiliteRepository($this->connexion))
            ->disponibilites([$offre, (string) $vide, '00000000-0000-4000-8000-000000000000', 'pas-un-uuid']);

        self::assertCount(2, $dispos, 'offre inconnue et identifiant invalide ignorés');
        self::assertSame(1, $dispos[$offre]->stockDisponible);
        self::assertSame(0, $dispos[(string) $vide]->stockDisponible);
        self::assertSame(
            (int) round((float) $this->connexion->fetchOne('SELECT prix_ttc FROM offre WHERE id = ?', [$offre]) * 100),
            $dispos[$offre]->prixCentimes,
        );
    }

    public function testReserverPuisStockInsuffisant(): void
    {
        $offre = $this->offreAvecStock(1, exact: true);
        $repo = new ReservationClesRepository($this->connexion);

        $cles = $repo->reserverCles($offre, 1, $this->nouvelleLigne($offre));
        self::assertCount(1, $cles);
        self::assertSame(0, (new OffreDisponibiliteRepository($this->connexion))->disponibilites([$offre])[$offre]->stockDisponible,
            'la clé réservée n\'est plus proposée aux autres paniers');

        try {
            $repo->reserverCles($offre, 1, $this->nouvelleLigne($offre));
            self::fail('SK001 attendu');
        } catch (StockInsuffisantException $e) {
            self::assertSame(0, $e->disponible());
        }
    }

    public function testCommandeExpireeNonReservable(): void
    {
        $offre = $this->offreAvecStock(2);
        $ligne = $this->nouvelleLigne($offre, expiree: true);

        $this->expectException(CommandeNonReservableException::class);
        (new ReservationClesRepository($this->connexion))->reserverCles($offre, 1, $ligne);
    }

    public function testLiberationDesReservationsExpirees(): void
    {
        $offre = $this->offreAvecStock(2);
        $ligne = $this->nouvelleLigne($offre);
        $repo = new ReservationClesRepository($this->connexion);
        $repo->reserverCles($offre, 1, $ligne);
        // la réservation dépasse ses 15 minutes
        $this->connexion->executeStatement(
            "UPDATE commande SET cree_le = now() - interval '20 minutes', expire_le = now() - interval '5 minutes'
              WHERE id = (SELECT commande_id FROM ligne_commande WHERE id = ?)", [$ligne]);

        self::assertGreaterThanOrEqual(1, $repo->libererReservationsExpirees());
        self::assertSame('EXPIREE', $this->connexion->fetchOne(
            'SELECT c.statut FROM commande c JOIN ligne_commande l ON l.commande_id = c.id WHERE l.id = ?', [$ligne]));
        self::assertSame(0, (int) $this->connexion->fetchOne('SELECT count(*) FROM cle_licence WHERE ligne_commande_id = ?', [$ligne]));
    }

    private function offreAvecStock(int $minimum, bool $exact = false): string
    {
        $offre = $this->connexion->fetchOne(
            "SELECT o.id FROM offre o JOIN cle_licence k ON k.offre_id = o.id AND k.statut = 'DISPONIBLE'
              WHERE o.actif GROUP BY o.id HAVING count(*) ".($exact ? '=' : '>=').' ? ORDER BY o.id LIMIT 1',
            [$minimum]);
        self::assertIsString($offre, 'le jeu d\'essai doit contenir cette offre (make db-seed)');

        return $offre;
    }

    private function nouvelleLigne(string $offre, bool $expiree = false): string
    {
        $commande = $this->connexion->fetchOne(
            "INSERT INTO commande (statut, montant_total, expire_le, utilisateur_id, langue_code, cree_le)
             SELECT 'EN_ATTENTE_PAIEMENT', o.prix_ttc, now() + (CASE WHEN ? THEN interval '-5 minutes' ELSE interval '15 minutes' END),
                    (SELECT id FROM utilisateur WHERE actif ORDER BY email LIMIT 1), 'fr',
                    now() - (CASE WHEN ? THEN interval '20 minutes' ELSE interval '0' END)
               FROM offre o WHERE o.id = ? RETURNING id",
            [$expiree, $expiree, $offre], [\Doctrine\DBAL\ParameterType::BOOLEAN, \Doctrine\DBAL\ParameterType::BOOLEAN]);

        return (string) $this->connexion->fetchOne(
            'INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id)
             SELECT 1, prix_ttc, ?, id FROM offre WHERE id = ? RETURNING id', [$commande, $offre]);
    }
}
