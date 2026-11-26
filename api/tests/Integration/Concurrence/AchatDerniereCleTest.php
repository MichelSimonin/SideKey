<?php

declare(strict_types=1);

namespace App\Tests\Integration\Concurrence;

use App\Exception\StockInsuffisantException;
use App\Repository\CommandeRepository;
use App\Repository\ReservationClesRepository;
use App\Service\Catalogue\Langue;
use App\Service\Commande\LigneACommander;
use App\Service\Panier\PanierId;
use App\Service\Panier\PanierStore;
use App\Tests\Support\Cles\ClesWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Group;

/**
 * Scénario de référence de F-06 (CDC §10.1) : plusieurs clients achètent la dernière clé au même instant.
 * Attendu : une seule commande, une seule clé réservée, aucune clé attribuée deux fois, aucune ligne de
 * commande sans clé (les perdants sont entièrement annulés).
 */
#[Group('database')]
#[Group('redis')]
final class AchatDerniereCleTest extends ClesWebTestCase
{
    private const ACHETEURS = 8;

    protected static function adresseIp(): string
    {
        return '198.51.100.91';
    }

    /**
     * Deux transactions entrelacées de façon déterministe : la première tient la dernière clé sous verrou,
     * exactement comme fn_reserver_cles pendant une validation de commande ; la seconde, sur une autre
     * connexion et par le vrai CommandeRepository, la saute (SKIP LOCKED), reçoit SK001 et n'écrit rien.
     * Elle réussit une fois la première annulée.
     */
    public function testUneTransactionQuiTientLaDerniereCleFaitEchouerLaSecondeSansAttente(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 1);
        $second = $this->creerAcheteur()['id'];
        $autre = DriverManager::getConnection($this->connexion->getParams(), $this->connexion->getConfiguration());
        $lignes = [new LigneACommander($offre, 1)];

        $this->connexion->beginTransaction();
        try {
            $this->connexion->executeStatement("SELECT id FROM cle_licence WHERE offre_id = CAST(:o AS uuid) AND statut = 'DISPONIBLE' FOR UPDATE", ['o' => $offre]);

            $debut = microtime(true);
            try {
                $this->depot($autre)->creer($second, Langue::Fr, false, $lignes, 900);
                self::fail('le second client ne doit pas obtenir la clé');
            } catch (StockInsuffisantException $e) {
                self::assertSame(0, $e->disponible());
            }
            self::assertLessThan(2.0, microtime(true) - $debut, 'SKIP LOCKED : le second n\'attend pas la fin du premier');
            self::assertSame(0, $autre->fetchOne('SELECT count(*) FROM commande WHERE utilisateur_id = CAST(:u AS uuid)', ['u' => $second]), 'tout ou rien : aucune commande ni ligne laissée');
            self::assertSame('READ COMMITTED', strtoupper((string) $autre->fetchOne('SHOW default_transaction_isolation')), 'niveau d\'isolation de la base (CDC §10.3)');
        } finally {
            $this->connexion->rollBack();   // le premier client abandonne
        }

        $commande = $this->depot($autre)->creer($second, Langue::Fr, false, $lignes, 900);
        self::assertSame(1, $autre->fetchOne("SELECT count(*) FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id WHERE l.commande_id = CAST(:c AS uuid) AND k.statut = 'RESERVEE'", ['c' => $commande->id]), 'la clé libérée revient au suivant');
        $autre->close();
    }

    /**
     * Vraie concurrence : ACHETEURS processus PHP indépendants (noyau Symfony et connexion PostgreSQL chacun)
     * passent commande de la dernière clé au même instant, par le vrai CommandeService.
     */
    public function testPlusieursAcheteursSimultanesUneSeuleVente(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 1);
        $store = static::getContainer()->get(PanierStore::class);
        self::assertInstanceOf(PanierStore::class, $store);
        $verrou = random_int(1_000_000, 9_000_000);
        $barriere = DriverManager::getConnection($this->connexion->getParams(), $this->connexion->getConfiguration());
        $barriere->executeStatement('SELECT pg_advisory_lock(CAST(:v AS bigint))', ['v' => $verrou]);

        $processus = [];
        $paniers = [];
        for ($i = 0; $i < self::ACHETEURS; ++$i) {
            $acheteur = $this->creerAcheteur()['id'];
            $this->connexion->executeStatement('UPDATE utilisateur SET email_verifie = true WHERE id = CAST(:u AS uuid)', ['u' => $acheteur]);
            $panier = self::uuid();
            $paniers[] = $panier;
            $store->definir(PanierId::visiteur($panier), $offre, 1);
            $processus[] = proc_open(
                [\PHP_BINARY, \dirname(__DIR__, 2).'/Support/Commande/acheteur_concurrent.php', $acheteur, $panier, (string) $verrou],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $tubes[$i],
            );
        }

        // Tous les acheteurs attendent devant la barrière, puis elle s'ouvre
        $attente = microtime(true);
        do {
            usleep(100_000);
            $prets = (int) $barriere->fetchOne("SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND objid = CAST(:v AS bigint) AND mode = 'ShareLock' AND NOT granted", ['v' => $verrou]);
        } while ($prets < self::ACHETEURS && microtime(true) - $attente < 30);
        self::assertSame(self::ACHETEURS, $prets, 'tous les acheteurs sont partis ensemble');
        $barriere->executeStatement('SELECT pg_advisory_unlock(CAST(:v AS bigint))', ['v' => $verrou]);

        $resultats = [];
        foreach ($processus as $i => $p) {
            $sortie = (string) stream_get_contents($tubes[$i][1]);
            $erreurs = (string) stream_get_contents($tubes[$i][2]);
            proc_close($p);
            $resultat = json_decode($sortie, true);
            self::assertIsArray($resultat, "acheteur $i : sortie inattendue : $sortie $erreurs");
            $resultats[] = $resultat['resultat'];
        }
        $barriere->close();
        foreach ($paniers as $panier) {
            $store->vider(PanierId::visiteur($panier));   // ne laisse rien dans Redis
        }

        $comptes = array_count_values($resultats);
        self::assertSame(1, $comptes['commande'] ?? 0, 'une seule vente : '.json_encode($comptes));
        // Les perdants ont été refusés soit à la réservation (SK001), soit plus tôt, en relisant leur panier
        // après la vente (stock 0 : panier modifié). Aucun autre motif n'est admis.
        self::assertSame([], array_diff(array_keys($comptes), ['commande', 'StockInsuffisantException', 'PanierModifieException']), json_encode($comptes));

        $etat = $this->connexion->fetchAssociative(
            <<<'SQL'
                SELECT (SELECT count(*) FROM cle_licence WHERE offre_id = CAST(:o AS uuid) AND statut = 'RESERVEE') AS reservees,
                       (SELECT count(*) FROM ligne_commande WHERE offre_id = CAST(:o AS uuid)) AS lignes,
                       (SELECT count(*) FROM ligne_commande l WHERE l.offre_id = CAST(:o AS uuid)
                           AND NOT EXISTS (SELECT 1 FROM cle_licence k WHERE k.ligne_commande_id = l.id)) AS lignes_sans_cle,
                       (SELECT count(DISTINCT ligne_commande_id) - count(*) FROM cle_licence WHERE offre_id = CAST(:o AS uuid) AND ligne_commande_id IS NOT NULL) AS doublons
                SQL,
            ['o' => $offre],
        );
        self::assertSame(['reservees' => 1, 'lignes' => 1, 'lignes_sans_cle' => 0, 'doublons' => 0], $etat);
    }

    private static function uuid(): string
    {
        $octets = random_bytes(16);
        $octets[6] = \chr(\ord($octets[6]) & 0x0F | 0x40);
        $octets[8] = \chr(\ord($octets[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($octets), 4));
    }

    private function depot(Connection $connexion): CommandeRepository
    {
        return new CommandeRepository($connexion, new ReservationClesRepository($connexion));
    }
}
