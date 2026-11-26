<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonPayableException;
use App\Exception\PaiementEnCoursException;
use App\Repository\PaiementRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Accès SQL du paiement (F-07) contre PostgreSQL, avec le rôle applicatif sk_app : ouverture d'une tentative, acceptation
 * atomique (paiement ET commande), refus, rejeu, et surtout les garanties de la BASE (index unique des paiements actifs,
 * fonction fn_valider_commande, triggers de transition et d'historique). Chaque test s'exécute dans une transaction annulée.
 */
#[Group('database')]
final class PaiementRepositoryTest extends KernelTestCase
{
    private Connection $connexion;
    private PaiementRepository $depot;

    protected function setUp(): void
    {
        self::bootKernel();
        $connexion = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $this->connexion = $connexion;
        $this->depot = new PaiementRepository($connexion);
        $this->connexion->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connexion->isTransactionActive()) {
            $this->connexion->rollBack();
        }
        parent::tearDown();
    }

    /** @return array{utilisateur: string, reference: string, commande: string} */
    private function commande(string $prix = '10.00', int $cles = 2, ?string $utilisateur = null, string $creeLe = 'now()'): array
    {
        $utilisateur ??= (string) $this->connexion->fetchOne(
            "INSERT INTO utilisateur (email, mot_de_passe_hash, prenom, nom, email_verifie, cree_le)
             VALUES (:e, 'h', 'Test', 'Paiement', true, ".$creeLe.') RETURNING id::text',
            ['e' => 'paiement-test-'.bin2hex(random_bytes(6)).'@example.test'],
        );
        $offre = (string) $this->connexion->fetchOne(
            <<<'SQL'
                INSERT INTO offre (prix_ttc, actif, jeu_id, plateforme_id)
                SELECT CAST(:p AS numeric), true, j.id, p.id FROM jeu j CROSS JOIN plateforme p
                 WHERE NOT EXISTS (SELECT 1 FROM offre o WHERE o.jeu_id = j.id AND o.plateforme_id = p.id AND o.actif)
                 ORDER BY random() LIMIT 1
                RETURNING id::text
                SQL,
            ['p' => $prix],
        );
        $commande = $this->connexion->fetchAssociative(
            "INSERT INTO commande (statut, montant_total, confirme_majeur, expire_le, utilisateur_id, langue_code)
             VALUES ('EN_ATTENTE_PAIEMENT', CAST(:t AS numeric) * :n, true, now() + interval '15 minutes', CAST(:u AS uuid), 'fr')
             RETURNING id::text AS id, reference::text AS reference",
            ['t' => $prix, 'n' => $cles, 'u' => $utilisateur],
        );
        \assert(\is_array($commande));
        $ligne = (string) $this->connexion->fetchOne(
            'INSERT INTO ligne_commande (quantite, prix_unitaire, commande_id, offre_id) VALUES (:n, CAST(:p AS numeric), CAST(:c AS uuid), CAST(:o AS uuid)) RETURNING id::text',
            ['n' => $cles, 'p' => $prix, 'c' => $commande['id'], 'o' => $offre],
        );
        for ($i = 0; $i < $cles; ++$i) {
            $this->connexion->executeStatement(
                "INSERT INTO cle_licence (cle_chiffree, empreinte, suffixe, statut, reservee_le, offre_id, ligne_commande_id)
                 VALUES (:c, :e, 'ABCD', 'RESERVEE', now(), CAST(:o AS uuid), CAST(:l AS uuid))",
                ['c' => random_bytes(40), 'e' => random_bytes(32), 'o' => $offre, 'l' => $ligne],
                ['c' => ParameterType::BINARY, 'e' => ParameterType::BINARY],
            );
        }

        return ['utilisateur' => $utilisateur, 'reference' => (string) $commande['reference'], 'commande' => (string) $commande['id']];
    }

    private static function cle(string $n = 'a'): string
    {
        return str_repeat($n, 64);
    }

    /** @return array<string, mixed> */
    private function ligne(string $sql, array $params = []): array
    {
        $ligne = $this->connexion->fetchAssociative($sql, $params);
        self::assertIsArray($ligne);

        return $ligne;
    }

    // ---- Lecture ------------------------------------------------------------------------------------------------

    public function testLeContexteRelitLeMontantLeNombreDeClesEtLAncienneteEnBase(): void
    {
        $c = $this->commande('24.50', 3);

        $contexte = $this->depot->contexte(strtoupper($c['reference']), $c['utilisateur']);

        self::assertNotNull($contexte);
        self::assertSame(7_350, $contexte->montantCentimes, 'centimes entiers, calculés par la base');
        self::assertSame(3, $contexte->nombreCles);
        self::assertSame('EN_ATTENTE_PAIEMENT', $contexte->statut);
        self::assertTrue($contexte->premiereCommande);
        self::assertLessThan(60, abs(time() - $contexte->compteCreeLe->getTimestamp()));
        self::assertGreaterThan(new \DateTimeImmutable('+14 minutes'), $contexte->expireLe);
    }

    public function testPremiereCommandeSeDecideSurLesCommandesDejaPayeesOuAVerifier(): void
    {
        $c = $this->commande();
        self::assertTrue($this->depot->contexte($c['reference'], $c['utilisateur'])?->premiereCommande);

        foreach (['ANNULEE' => true, 'EXPIREE' => true, 'PAYEE' => false] as $statut => $premiere) {
            $utilisateur = $this->commande()['utilisateur'];
            $autre = $this->commande(utilisateur: $utilisateur);
            $this->connexion->executeStatement("UPDATE commande SET statut = 'EN_ATTENTE_PAIEMENT' WHERE id = CAST(:c AS uuid)", ['c' => $autre['commande']]);
            $precedente = $this->commande(utilisateur: $utilisateur);
            $this->connexion->executeStatement('UPDATE commande SET statut = :s WHERE id = CAST(:c AS uuid)', ['s' => $statut, 'c' => $precedente['commande']]);

            self::assertSame($premiere, $this->depot->contexte($autre['reference'], $utilisateur)?->premiereCommande, 'commande précédente '.$statut);
        }
    }

    public function testUneCommandeDAutruiOuForgeeNExistePasPourLeClient(): void
    {
        $c = $this->commande();
        $intrus = $this->commande()['utilisateur'];

        self::assertNull($this->depot->contexte($c['reference'], $intrus));
        self::assertNull($this->depot->contexte("1' OR '1'='1", $c['utilisateur']));
        self::assertNull($this->depot->contexte('../../etc/passwd', $c['utilisateur']));
        $this->expectException(CommandeIntrouvableException::class);
        $this->depot->ouvrir($c['reference'], $intrus, self::cle(), 30);
    }

    // ---- Ouverture : un seul paiement actif par commande --------------------------------------------------------

    public function testOuvrirEnregistreUneTentativeAuMontantDeLaCommande(): void
    {
        $c = $this->commande('10.00', 3);

        $ouvert = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('b'), 30);

        self::assertSame($c['commande'], $ouvert->commandeId);
        $p = $this->ligne('SELECT montant::text AS montant, statut, cle_idempotence, code_retour, derniers_chiffres FROM paiement WHERE id = CAST(:p AS uuid)', ['p' => $ouvert->paiementId]);
        self::assertSame(['30.00', 'EN_ATTENTE', self::cle('b'), null, null], array_values($p), 'le montant est celui de la commande, jamais envoyé par le client');
    }

    public function testUnSecondPaiementActifDeLaMemeCommandeEstRefuseParLaBase(): void
    {
        $c = $this->commande();
        $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('a'), 30);

        try {
            $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('b'), 30);
            self::fail('Un second paiement actif aurait dû être refusé.');
        } catch (PaiementEnCoursException) {
        }
        self::assertSame(1, $this->connexion->fetchOne('SELECT count(*) FROM paiement WHERE commande_id = CAST(:c AS uuid)', ['c' => $c['commande']]), 'aucun second débit possible');
    }

    public function testLaMemeCleEnParalleleEstRefusee(): void
    {
        $c = $this->commande();
        $autre = $this->commande();
        $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('a'), 30);

        $this->expectException(PaiementEnCoursException::class);
        $this->depot->ouvrir($autre['reference'], $autre['utilisateur'], self::cle('a'), 30);   // uq_paiement_idempotence
    }

    public function testUnPaiementRefuseNeBloquePasUnNouvelEssai(): void
    {
        $c = $this->commande();
        $premier = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('a'), 30);
        $this->depot->terminer($premier->paiementId, 'REFUSE', 'carte_refusee', '0002');

        $second = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('b'), 30);

        self::assertNotSame($premier->paiementId, $second->paiementId);
        self::assertEqualsCanonicalizing(['REFUSE', 'EN_ATTENTE'], $this->connexion->fetchFirstColumn('SELECT statut FROM paiement WHERE commande_id = CAST(:c AS uuid)', ['c' => $c['commande']]));
    }

    /** @return iterable<string, array{string}> */
    public static function statutsNonPayables(): iterable
    {
        foreach (['PAYEE', 'A_VERIFIER', 'EXPIREE', 'ANNULEE', 'REMBOURSEE'] as $statut) {
            yield $statut => [$statut];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('statutsNonPayables')]
    public function testSeuleUneCommandeEnAttenteSeOuvre(string $statut): void
    {
        $c = $this->commande();
        // Uniquement des transitions autorisées par trg_commande_transition (CDC §6.4)
        foreach ('REMBOURSEE' === $statut ? ['A_VERIFIER', 'REMBOURSEE'] : [$statut] as $etape) {
            $this->connexion->executeStatement('UPDATE commande SET statut = :s WHERE id = CAST(:c AS uuid)', ['s' => $etape, 'c' => $c['commande']]);
        }

        $this->expectException(CommandeNonPayableException::class);
        $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);
    }

    public function testUneReservationExpireeOuProcheDeLEcheanceNEstPasOuverte(): void
    {
        $c = $this->commande();
        $this->connexion->executeStatement("UPDATE commande SET expire_le = now() + interval '20 seconds' WHERE id = CAST(:c AS uuid)", ['c' => $c['commande']]);

        try {
            $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);   // marge de 30 s
            self::fail('Une réservation qui expire dans 20 s ne s\'ouvre pas.');
        } catch (CommandeNonPayableException) {
        }
        self::assertSame(0, $this->connexion->fetchOne('SELECT count(*) FROM paiement WHERE commande_id = CAST(:c AS uuid)', ['c' => $c['commande']]));

        $this->connexion->executeStatement("UPDATE commande SET expire_le = now() + interval '2 minutes' WHERE id = CAST(:c AS uuid)", ['c' => $c['commande']]);
        self::assertNotNull($this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30));
    }

    public function testUneTentativeAbandonneeNeBloquePasLaCommande(): void
    {
        $c = $this->commande();
        $abandonnee = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('a'), 30);
        // Le processus est mort avant la réponse du prestataire : la tentative est restée EN_ATTENTE
        $this->connexion->executeStatement("UPDATE paiement SET cree_le = now() - interval '5 minutes' WHERE id = CAST(:p AS uuid)", ['p' => $abandonnee->paiementId]);

        $nouvelle = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('b'), 30);

        self::assertNotSame($abandonnee->paiementId, $nouvelle->paiementId);
        $ancienne = $this->ligne('SELECT statut, code_retour FROM paiement WHERE id = CAST(:p AS uuid)', ['p' => $abandonnee->paiementId]);
        self::assertSame(['ERREUR', 'abandonne'], array_values($ancienne));
    }

    public function testUneTentativeRecenteBloqueEncoreLaCommande(): void
    {
        $c = $this->commande();
        $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('a'), 30);

        $this->expectException(PaiementEnCoursException::class);   // 0 s d'âge : le prestataire peut encore répondre
        $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle('b'), 30);
    }

    // ---- Acceptation atomique -----------------------------------------------------------------------------------

    public function testAccepterVendLesClesEtPasseLaCommandeEnPayeeAvecSonHistorique(): void
    {
        $c = $this->commande('10.00', 2);
        $ouvert = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);

        $statut = $this->depot->accepter($ouvert->paiementId, 'ok', '4242', false, $c['utilisateur']);

        self::assertSame('PAYEE', $statut);
        $commande = $this->ligne('SELECT statut, payee_le IS NOT NULL AS payee FROM commande WHERE id = CAST(:c AS uuid)', ['c' => $c['commande']]);
        self::assertSame(['PAYEE', true], array_values($commande));
        self::assertSame(2, $this->connexion->fetchOne("SELECT count(*) FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id WHERE l.commande_id = CAST(:c AS uuid) AND k.statut = 'VENDUE'", ['c' => $c['commande']]));
        $paiement = $this->ligne('SELECT statut, code_retour, derniers_chiffres FROM paiement WHERE id = CAST(:p AS uuid)', ['p' => $ouvert->paiementId]);
        self::assertSame(['ACCEPTE', 'ok', '4242'], array_values($paiement));
        $historique = $this->ligne("SELECT ancien_statut, nouveau_statut, auteur_id::text AS auteur FROM commande_historique WHERE commande_id = CAST(:c AS uuid) AND nouveau_statut = 'PAYEE'", ['c' => $c['commande']]);
        self::assertSame(['EN_ATTENTE_PAIEMENT', 'PAYEE', $c['utilisateur']], array_values($historique), 'RG-21 : qui a fait quoi');
    }

    public function testAccepterUnePaiementSuspectGardeLesClesReserveesEtPasseEnAVerifier(): void
    {
        $c = $this->commande('160.00', 1);
        $ouvert = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);

        $statut = $this->depot->accepter($ouvert->paiementId, 'ok', '4242', true, $c['utilisateur']);

        self::assertSame('A_VERIFIER', $statut);
        self::assertSame('A_VERIFIER', $this->connexion->fetchOne('SELECT statut FROM commande WHERE id = CAST(:c AS uuid)', ['c' => $c['commande']]));
        self::assertSame(1, $this->connexion->fetchOne("SELECT count(*) FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id WHERE l.commande_id = CAST(:c AS uuid) AND k.statut = 'RESERVEE'", ['c' => $c['commande']]), 'clés conservées, non vendues, non révélables');
        self::assertSame('ACCEPTE', $this->connexion->fetchOne('SELECT statut FROM paiement WHERE id = CAST(:p AS uuid)', ['p' => $ouvert->paiementId]), 'le paiement, lui, est accepté');
        self::assertNull($this->connexion->fetchOne('SELECT payee_le FROM commande WHERE id = CAST(:c AS uuid)', ['c' => $c['commande']]));
    }

    public function testLaLiberationDesReservationsExpireesNeTouchePasUneCommandeAVerifier(): void
    {
        $c = $this->commande('160.00', 1);
        $ouvert = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);
        $this->depot->accepter($ouvert->paiementId, 'ok', '4242', true, $c['utilisateur']);
        $this->connexion->executeStatement("UPDATE commande SET cree_le = now() - interval '2 hours', expire_le = now() - interval '1 hour' WHERE id = CAST(:c AS uuid)", ['c' => $c['commande']]);

        $this->connexion->fetchOne('SELECT fn_liberer_reservations_expirees()');

        self::assertSame('A_VERIFIER', $this->connexion->fetchOne('SELECT statut FROM commande WHERE id = CAST(:c AS uuid)', ['c' => $c['commande']]), 'une commande à vérifier attend la décision du back-office');
        self::assertSame(1, $this->connexion->fetchOne("SELECT count(*) FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id WHERE l.commande_id = CAST(:c AS uuid) AND k.statut = 'RESERVEE'", ['c' => $c['commande']]), 'ses clés ne sont pas remises en vente');
    }

    /** @return iterable<string, array{bool}> */
    public static function suspect(): iterable
    {
        yield 'validation normale (fn_valider_commande)' => [false];
        yield 'à vérifier' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('suspect')]
    public function testUneReservationExpireePendantLAppelAnnuleToutEtLaisseLePaiementEnAttente(bool $suspecte): void
    {
        $c = $this->commande();
        $ouvert = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);
        $this->connexion->executeStatement("UPDATE commande SET cree_le = now() - interval '2 hours', expire_le = now() - interval '1 minute' WHERE id = CAST(:c AS uuid)", ['c' => $c['commande']]);

        try {
            $this->depot->accepter($ouvert->paiementId, 'ok', '4242', $suspecte, $c['utilisateur']);
            self::fail('La commande ne peut plus être validée.');
        } catch (CommandeNonPayableException) {
        }

        self::assertSame('EN_ATTENTE_PAIEMENT', $this->connexion->fetchOne('SELECT statut FROM commande WHERE id = CAST(:c AS uuid)', ['c' => $c['commande']]), 'tout ou rien');
        self::assertSame('EN_ATTENTE', $this->connexion->fetchOne('SELECT statut FROM paiement WHERE id = CAST(:p AS uuid)', ['p' => $ouvert->paiementId]), 'la mise à jour du paiement a été annulée avec le reste');
        self::assertSame(2, $this->connexion->fetchOne("SELECT count(*) FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id WHERE l.commande_id = CAST(:c AS uuid) AND k.statut = 'RESERVEE'", ['c' => $c['commande']]));
    }

    public function testAccepterUnePaiementAbandonneNeValideRien(): void
    {
        $c = $this->commande();
        $ouvert = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);
        $this->depot->terminer($ouvert->paiementId, 'ERREUR', 'abandonne', null);

        $this->expectException(CommandeNonPayableException::class);
        $this->depot->accepter($ouvert->paiementId, 'ok', '4242', false, $c['utilisateur']);
    }

    // ---- Refus, rejeu -------------------------------------------------------------------------------------------

    public function testTerminerEnregistreLeRefusSansToucherLaCommandeEtNeSeRejouePas(): void
    {
        $c = $this->commande();
        $ouvert = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);

        $this->depot->terminer($ouvert->paiementId, 'REFUSE', 'fonds_insuffisants', '9995');
        $this->depot->terminer($ouvert->paiementId, 'ACCEPTE', 'ok', '4242');   // déjà terminé : sans effet

        $p = $this->ligne('SELECT statut, code_retour, derniers_chiffres FROM paiement WHERE id = CAST(:p AS uuid)', ['p' => $ouvert->paiementId]);
        self::assertSame(['REFUSE', 'fonds_insuffisants', '9995'], array_values($p));
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->connexion->fetchOne('SELECT statut FROM commande WHERE id = CAST(:c AS uuid)', ['c' => $c['commande']]));
    }

    public function testParCleRetrouveLaTentativeDeSonClientSeulement(): void
    {
        $c = $this->commande();
        $intrus = $this->commande()['utilisateur'];
        $ouvert = $this->depot->ouvrir($c['reference'], $c['utilisateur'], self::cle(), 30);
        $this->depot->terminer($ouvert->paiementId, 'REFUSE', 'carte_refusee', '0002');

        $trouve = $this->depot->parCle(self::cle(), $c['utilisateur']);

        self::assertNotNull($trouve);
        self::assertSame([$ouvert->paiementId, 'REFUSE', 'carte_refusee', '0002', $c['reference']], [$trouve->paiementId, $trouve->statut, $trouve->codeRetour, $trouve->derniersChiffres, $trouve->commandeReference]);
        self::assertNull($this->depot->parCle(self::cle(), $intrus), 'la clé d\'un autre client n\'existe pas');
        self::assertNull($this->depot->parCle(self::cle('z'), $c['utilisateur']));
        self::assertNull($this->depot->parCle("x' OR '1'='1", $c['utilisateur']));
    }

    // ---- Aucune donnée de carte en base -------------------------------------------------------------------------

    public function testLaTableDesPaiementsNeContientAucuneColonneDeCarte(): void
    {
        $colonnes = $this->connexion->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'paiement' ORDER BY column_name");

        self::assertSame(['cle_idempotence', 'code_retour', 'commande_id', 'cree_le', 'derniers_chiffres', 'id', 'modifie_le', 'montant', 'statut'], $colonnes);
        self::assertSame('character', $this->connexion->fetchOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'paiement' AND column_name = 'derniers_chiffres'"));
        self::assertSame(4, (int) $this->connexion->fetchOne("SELECT character_maximum_length FROM information_schema.columns WHERE table_name = 'paiement' AND column_name = 'derniers_chiffres'"), 'quatre caractères au plus : un numéro complet ne tient pas');
    }
}
