<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Paiement;

use App\Exception\CommandeIntrouvableException;
use App\Exception\CommandeNonPayableException;
use App\Exception\IdempotenceConflitException;
use App\Exception\LimiteClesException;
use App\Exception\PaiementBloqueException;
use App\Exception\PaiementEnCoursException;
use App\Exception\PaiementIndisponibleException;
use App\Exception\PaiementInvalideException;
use App\Exception\PaiementRefuseException;
use App\Exception\TropDeTentativesException;
use App\Infrastructure\Payment\FournisseurPaiementSimule;
use App\Service\Audit\JournalAudit;
use App\Service\Auth\Compte;
use App\Service\Catalogue\Langue;
use App\Service\Commande\CommandeService;
use App\Service\Commande\LigneACommander;
use App\Service\Paiement\ConfigurationFraude;
use App\Service\Paiement\PaiementService;
use App\Service\Paiement\PolitiqueFraude;
use App\Service\Paiement\ResultatPaiement;
use App\Service\Panier\PanierService;
use App\Tests\Support\Auth\LimiteurEnMemoire;
use App\Tests\Support\Catalogue\CacheEnMemoire;
use App\Tests\Support\CatalogueOffresEnMemoire;
use App\Tests\Support\Cles\JournalAuditEnMemoire;
use App\Tests\Support\Commande\CommandesEnMemoire;
use App\Tests\Support\Commande\MinuteurEnMemoire;
use App\Tests\Support\Paiement\CompteursFraudeEnMemoire;
use App\Tests\Support\Paiement\FournisseurScripte;
use App\Tests\Support\Paiement\IdempotenceEnMemoire;
use App\Tests\Support\Paiement\PaiementsEnMemoire;
use App\Tests\Support\PanierStoreEnMemoire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;

/**
 * US-32, US-33, US-36, RG-14 : le déroulé complet d'un paiement, avec des doublures pour la base, Redis et le prestataire.
 * Chaque test vérifie un effet précis ET ce qui ne doit PAS se produire (pas d'appel au prestataire, pas de compteur, pas de
 * trace en base) : une protection se prouve autant par ce qu'elle empêche que par ce qu'elle laisse passer.
 */
final class PaiementServiceTest extends TestCase
{
    private const CLIENT = '11111111-2222-4333-8444-555555555555';
    private const AUTRE_CLIENT = '99999999-2222-4333-8444-555555555555';
    private const OFFRE = '0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b';
    private const OFFRE_CHERE = '9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a';
    private const OK = '4242424242424242';
    private const REFUSEE = '4000000000000002';
    private const IP = '203.0.113.9';

    private MockClock $horloge;
    private CommandesEnMemoire $commandes;
    private PaiementsEnMemoire $paiements;
    private FournisseurScripte $psp;
    private CompteursFraudeEnMemoire $compteurs;
    private IdempotenceEnMemoire $idempotence;
    private JournalAuditEnMemoire $audit;
    private LimiteurEnMemoire $limiteur;
    private MinuteurEnMemoire $minuteur;
    private PaiementService $service;
    /** @var list<string> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->horloge = new MockClock();
        $this->commandes = new CommandesEnMemoire();
        $this->commandes->ajouterOffre(self::OFFRE, 1999, 100);
        $this->commandes->ajouterOffre(self::OFFRE_CHERE, 15_100, 100);
        $this->paiements = new PaiementsEnMemoire($this->commandes, $this->horloge);
        $this->psp = new FournisseurScripte();
        $this->psp->delegue = new FournisseurPaiementSimule();
        $configuration = new ConfigurationFraude(10, 3, 3600, 24, 150, 200);
        $this->compteurs = new CompteursFraudeEnMemoire($configuration);
        $this->idempotence = new IdempotenceEnMemoire();
        $this->audit = new JournalAuditEnMemoire();
        $this->limiteur = new LimiteurEnMemoire();
        $this->minuteur = new MinuteurEnMemoire();
        $commandeService = new CommandeService($this->commandes, new PanierService(new PanierStoreEnMemoire(), new CatalogueOffresEnMemoire()), $this->minuteur, new CacheEnMemoire());
        $logs = &$this->logs;
        $logger = new class($logs) extends AbstractLogger {
            /** @param list<string> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = $level.' '.$message.' '.json_encode($context);
            }
        };
        $this->service = new PaiementService(
            $this->paiements, $this->psp, $this->compteurs, $this->idempotence, new PolitiqueFraude($configuration),
            $this->limiteur, $commandeService, $this->minuteur, $this->audit, $this->horloge, $logger,
        );
    }

    private function compte(string $id = self::CLIENT): Compte
    {
        return new Compte($id, 'lea@example.test', 'h', 'Léa', 'Roy', 'fr', ['CLIENT'], false, true, true);
    }

    /** Commande EN_ATTENTE_PAIEMENT de $quantite clés de l'offre, pour $client ; renvoie sa référence. */
    private function commander(int $quantite = 1, string $offre = self::OFFRE, string $client = self::CLIENT): string
    {
        return $this->commandes->creer($client, Langue::Fr, false, [new LigneACommander($offre, $quantite)], 900)->reference;
    }

    private function payer(string $reference, string $carte = self::OK, ?string $cle = null, string $client = self::CLIENT): \App\Service\Paiement\PaiementEffectue
    {
        return $this->service->payer($this->compte($client), $reference, $carte, '12/30', '123', $cle ?? bin2hex(random_bytes(10)), self::IP);
    }

    // ---- Succès -------------------------------------------------------------------------------------------------

    public function testUnPaiementAccepteValideLaCommandeEtAvanceLesCompteurs(): void
    {
        $reference = $this->commander(2);

        $paiement = $this->payer($reference);

        self::assertSame('PAYEE', $paiement->commande->statut);
        self::assertSame('4242', $paiement->derniersChiffres);
        self::assertFalse($paiement->rejeu);
        self::assertSame(['ACCEPTE'], $this->paiements->statuts());
        self::assertCount(1, $this->psp->demandes, 'un seul appel au prestataire');
        self::assertSame(3_998, $this->psp->demandes[0]->montantCentimes, 'le montant vient de la commande en base, jamais du client (RG-11)');
        self::assertCount(2, $this->compteurs->cles[self::CLIENT], '2 clés comptées sur la fenêtre de 24 h');
        self::assertSame([], $this->compteurs->echecs);
        self::assertSame([JournalAudit::PAIEMENT_ACCEPTE], array_column($this->audit->entrees, 'action'));
        self::assertSame('paiement', $this->audit->entrees[0]['ressource']);
        self::assertSame(self::CLIENT, $this->audit->entrees[0]['utilisateurId']);
    }

    public function testUnPaiementAccepteArreteLeMinuteurDeReservation(): void
    {
        $reference = $this->commander();
        $id = $this->commandes->commandes[$reference]['id'];
        $this->minuteur->minuteurs[$id] = new \DateTimeImmutable('+10 minutes');

        $this->payer($reference);

        self::assertArrayNotHasKey($id, $this->minuteur->minuteurs);
    }

    public function testLeMontantEnvoyeAuPrestataireEstEnCentimesEntiers(): void
    {
        $reference = $this->commander(1, self::OFFRE_CHERE);

        $this->payer($reference);

        self::assertSame(15_100, $this->psp->demandes[0]->montantCentimes);
    }

    // ---- Cartes de test, refus, nouvel essai --------------------------------------------------------------------

    /** @return iterable<string, array{string, string, string}> */
    public static function refus(): iterable
    {
        yield 'carte refusée' => ['4000000000000002', 'carte_refusee', 'REFUSE'];
        yield 'fonds insuffisants' => ['4000000000009995', 'fonds_insuffisants', 'REFUSE'];
        yield 'délai dépassé' => ['4000000000000119', 'delai_depasse', 'ERREUR'];
        yield 'carte inconnue' => ['5555555555554444', 'carte_inconnue', 'REFUSE'];
    }

    #[DataProvider('refus')]
    public function testUnRefusLaisseLaCommandeEnAttenteAvecSesClesReservees(string $carte, string $code, string $statutPaiement): void
    {
        $reference = $this->commander();

        try {
            $this->payer($reference, $carte);
            self::fail('Le paiement aurait dû être refusé.');
        } catch (PaiementRefuseException $e) {
            self::assertSame(402, $e->codeHttp());
            self::assertSame(['code' => $code, 'tentativesRestantes' => 2], $e->details());
        }

        self::assertSame([$statutPaiement], $this->paiements->statuts());
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->commandes->commandes[$reference]['statut'], 'US-36 : réservation conservée pour réessayer');
        self::assertSame([self::CLIENT => 1], $this->compteurs->echecs);
        self::assertSame([], $this->compteurs->cles, 'un refus ne compte aucune clé achetée');
        self::assertSame([JournalAudit::PAIEMENT_REFUSE], array_column($this->audit->entrees, 'action'));
    }

    public function testApresUnRefusLeClientReessaieAvecUneNouvelleCleEtUneAutreCarte(): void
    {
        $reference = $this->commander();
        try {
            $this->payer($reference, self::REFUSEE);
        } catch (PaiementRefuseException) {
            // attendu
        }

        $paiement = $this->payer($reference, self::OK);

        self::assertSame('PAYEE', $paiement->commande->statut);
        self::assertSame(['REFUSE', 'ACCEPTE'], $this->paiements->statuts());
        self::assertSame([], $this->compteurs->echecs, 'un paiement accepté remet les échecs à zéro');
    }

    // ---- Blocage après 3 échecs ---------------------------------------------------------------------------------

    public function testLeTroisiemeEchecBloqueLeClientUneHeure(): void
    {
        $reference = $this->commander();
        $restantes = [];
        for ($i = 0; $i < 3; ++$i) {
            try {
                $this->payer($reference, self::REFUSEE);
            } catch (PaiementRefuseException $e) {
                $restantes[] = $e->details()['tentativesRestantes'];
            }
        }

        self::assertSame([2, 1, 0], $restantes);
        self::assertSame(3600, $this->compteurs->blocages[self::CLIENT]);
        self::assertContains(JournalAudit::PAIEMENT_BLOQUE, array_column($this->audit->entrees, 'action'));

        $appelsAvant = \count($this->psp->demandes);
        try {
            $this->payer($reference, self::OK);
            self::fail('Un client bloqué ne peut pas payer, même avec une bonne carte.');
        } catch (PaiementBloqueException $e) {
            self::assertSame(429, $e->codeHttp());
            self::assertSame(['Retry-After' => '3600'], $e->entetes());
            self::assertSame(['%minutes%' => 60], $e->parametres());
        }
        self::assertCount($appelsAvant, $this->psp->demandes, 'le prestataire n\'est pas appelé pour un client bloqué');
    }

    public function testDeuxEchecsPuisUnSuccesNeBloquentPas(): void
    {
        $reference = $this->commander();
        for ($i = 0; $i < 2; ++$i) {
            try {
                $this->payer($reference, self::REFUSEE);
            } catch (PaiementRefuseException) {
            }
        }
        $this->payer($reference, self::OK);

        self::assertSame([], $this->compteurs->blocages);
        self::assertSame([], $this->compteurs->echecs);
    }

    public function testUneErreurDuPrestataireCompteCommeUnEchec(): void
    {
        $this->psp->exception = new \RuntimeException('connexion refusée 4242424242424242');
        $reference = $this->commander();

        try {
            $this->payer($reference);
            self::fail('Le paiement aurait dû échouer.');
        } catch (PaiementRefuseException $e) {
            self::assertSame(['code' => 'erreur_fournisseur', 'tentativesRestantes' => 2], $e->details());
        }

        self::assertSame(['ERREUR'], $this->paiements->statuts(), 'jamais supposé accepté');
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->commandes->commandes[$reference]['statut']);
        self::assertStringNotContainsString('4242424242424242', implode("\n", $this->logs), 'le message du prestataire, qui peut citer la carte, n\'est pas journalisé');
        self::assertStringContainsString('RuntimeException', implode("\n", $this->logs));
    }

    // ---- Idempotence --------------------------------------------------------------------------------------------

    public function testRejouerLaMemeCleNeDebitePasDeuxFoisEtRenvoieLaMemeReponse(): void
    {
        $reference = $this->commander();
        $cle = '0123456789abcdef0123';

        $premier = $this->payer($reference, self::OK, $cle);
        $rejeu = $this->payer($reference, self::OK, $cle);

        self::assertFalse($premier->rejeu);
        self::assertTrue($rejeu->rejeu);
        self::assertSame($premier->commande->reference, $rejeu->commande->reference);
        self::assertSame('PAYEE', $rejeu->commande->statut);
        self::assertSame('4242', $rejeu->derniersChiffres);
        self::assertCount(1, $this->psp->demandes, 'un seul appel au prestataire');
        self::assertSame(['ACCEPTE'], $this->paiements->statuts(), 'un seul paiement');
        self::assertCount(1, $this->audit->entrees, 'un rejeu ne trace pas un second paiement');
        self::assertSame(1, $this->limiteur->compteurs['rl:pay:'.self::CLIENT], 'un rejeu ne consomme pas de tentative');
    }

    public function testRejouerUnRefusRenvoieLeMemeRefusSansRappelerLePrestataire(): void
    {
        $reference = $this->commander();
        $cle = '0123456789abcdef0123';
        try {
            $this->payer($reference, self::REFUSEE, $cle);
        } catch (PaiementRefuseException) {
        }

        try {
            $this->payer($reference, self::OK, $cle);   // même clé, autre carte : c'est un rejeu, pas un nouvel essai
            self::fail('Le refus aurait dû être rejoué.');
        } catch (PaiementRefuseException $e) {
            self::assertSame('carte_refusee', $e->details()['code']);
        }
        self::assertCount(1, $this->psp->demandes);
        self::assertSame([self::CLIENT => 1], $this->compteurs->echecs, 'un rejeu ne compte pas un second échec');
    }

    public function testUneCleDejaUtiliseePourUneAutreCommandeEstRefusee(): void
    {
        $premiere = $this->commander();
        $cle = '0123456789abcdef0123';
        $this->payer($premiere, self::OK, $cle);
        $seconde = $this->commander();

        $this->expectException(IdempotenceConflitException::class);
        $this->payer($seconde, self::OK, $cle);
    }

    public function testDeuxEnvoisSimultanesDeLaMemeCleNAtteignentPasDeuxFoisLePrestataire(): void
    {
        $reference = $this->commander();
        $cle = '0123456789abcdef0123';
        $this->idempotence->cles[\App\Service\Paiement\CleIdempotence::depuis($cle, self::CLIENT)->empreinte] = true;   // le premier envoi a réservé la clé

        try {
            $this->payer($reference, self::OK, $cle);
            self::fail('Un paiement en cours doit être signalé.');
        } catch (PaiementEnCoursException $e) {
            self::assertSame(409, $e->codeHttp());
        }
        self::assertSame([], $this->psp->demandes);
        self::assertSame([], $this->paiements->paiements);
    }

    public function testUnSecondPaiementDeLaMemeCommandeAvecUneAutreCleEstRefuse(): void
    {
        $reference = $this->commander();
        $this->paiements->ouvrir($reference, self::CLIENT, str_repeat('a', 64), 30);   // un paiement est déjà en cours

        try {
            $this->payer($reference, self::OK);
            self::fail('Le second paiement aurait dû être refusé.');
        } catch (PaiementEnCoursException) {
        }
        self::assertSame([], $this->psp->demandes, 'pas de double débit possible');
        self::assertCount(1, $this->paiements->paiements);
        self::assertSame([], $this->idempotence->cles, 'la clé réservée est rendue : rien n\'a été écrit');
    }

    // ---- État de la commande ------------------------------------------------------------------------------------

    public function testUneCommandeDAutruiOuInconnueEstIntrouvableSansRienEcrire(): void
    {
        $celleDeMarcus = $this->commander(1, self::OFFRE, self::AUTRE_CLIENT);

        foreach ([$celleDeMarcus, '00000000-0000-4000-8000-00000000ffff', 'pas-un-uuid'] as $reference) {
            try {
                $this->payer($reference);
                self::fail('La commande aurait dû être introuvable.');
            } catch (CommandeIntrouvableException $e) {
                self::assertSame(404, $e->codeHttp());
            }
        }
        self::assertSame([], $this->psp->demandes);
        self::assertSame([], $this->paiements->paiements);
        self::assertSame([], $this->idempotence->cles);
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->commandes->commandes[$celleDeMarcus]['statut']);
    }

    public function testUneCommandeDejaPayeeNePeutPasEtrePayeeDeNouveau(): void
    {
        $reference = $this->commander();
        $this->payer($reference);

        try {
            $this->payer($reference);   // nouvelle clé : ce n'est pas un rejeu
            self::fail('Un second paiement aurait dû être refusé.');
        } catch (CommandeNonPayableException $e) {
            self::assertSame(409, $e->codeHttp());
        }
        self::assertCount(1, $this->psp->demandes, 'aucun second débit');
    }

    /** @return iterable<string, array{string}> */
    public static function statutsNonPayables(): iterable
    {
        yield 'à vérifier' => ['A_VERIFIER'];
        yield 'expirée' => ['EXPIREE'];
        yield 'annulée' => ['ANNULEE'];
        yield 'remboursée' => ['REMBOURSEE'];
    }

    #[DataProvider('statutsNonPayables')]
    public function testSeuleUneCommandeEnAttenteDePaiementSePaye(string $statut): void
    {
        $reference = $this->commander();
        $this->commandes->commandes[$reference]['statut'] = $statut;

        $this->expectException(CommandeNonPayableException::class);
        $this->payer($reference);
    }

    public function testUneReservationExpireeOuSurLePointDExpirerNEstPasPayee(): void
    {
        $reference = $this->commander();

        $this->commandes->commandes[$reference]['expire'] = $this->horloge->now()->modify('-1 second');
        try {
            $this->payer($reference);
            self::fail('Une réservation expirée ne se paie pas.');
        } catch (CommandeNonPayableException) {
        }

        // Moins de 30 s : le prestataire serait appelé, puis la validation échouerait APRÈS le débit
        $this->commandes->commandes[$reference]['expire'] = $this->horloge->now()->modify('+20 seconds');
        try {
            $this->payer($reference);
            self::fail('Une réservation qui expire dans 20 s ne se paie pas.');
        } catch (CommandeNonPayableException) {
        }
        self::assertSame([], $this->psp->demandes);

        $this->commandes->commandes[$reference]['expire'] = $this->horloge->now()->modify('+2 minutes');
        self::assertSame('PAYEE', $this->payer($reference)->commande->statut);
    }

    public function testAccepteMaisReservationExpireeDurantLAppelEstTraceeARembourser(): void
    {
        $reference = $this->commander();
        $this->paiements->expireDurantLAppel = true;

        try {
            $this->payer($reference);
            self::fail('La commande n\'est plus validable.');
        } catch (CommandeNonPayableException) {
        }

        self::assertSame(['ERREUR'], $this->paiements->statuts());
        self::assertSame('accepte_non_valide', array_values($this->paiements->paiements)[0]['code']);
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->commandes->commandes[$reference]['statut']);
        self::assertSame([JournalAudit::PAIEMENT_A_REMBOURSER], array_column($this->audit->entrees, 'action'));
        self::assertSame([], $this->compteurs->cles, 'aucune clé comptée : la commande n\'est pas payée');
        self::assertStringContainsString('critical', implode("\n", $this->logs));
    }

    // ---- RG-14 : plafond de clés, commande à vérifier -----------------------------------------------------------

    public function testLePlafondDeDixClesSurVingtQuatreHeuresRefuseAvantTouteDebit(): void
    {
        $this->compteurs->cles[self::CLIENT] = array_fill_keys(array_map(static fn (int $i): string => 'ancienne:'.$i, range(1, 6)), true);
        $reference = $this->commander(5);   // 6 + 5 = 11 > 10

        try {
            $this->payer($reference);
            self::fail('Le plafond aurait dû refuser le paiement.');
        } catch (LimiteClesException $e) {
            self::assertSame(422, $e->codeHttp());
            self::assertSame(['%maximum%' => 10], $e->parametres());
        }
        self::assertSame([], $this->psp->demandes, 'refusé AVANT le prestataire : le client n\'est pas débité');
        self::assertSame([], $this->paiements->paiements);
        self::assertSame([], $this->compteurs->echecs, 'ce n\'est pas un échec de paiement');
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->commandes->commandes[$reference]['statut'], 'la commande reste annulable');
    }

    public function testExactementDixClesSontAutorisees(): void
    {
        $this->compteurs->cles[self::CLIENT] = array_fill_keys(array_map(static fn (int $i): string => 'ancienne:'.$i, range(1, 5)), true);
        $reference = $this->commander(5);   // 5 + 5 = 10

        self::assertSame('PAYEE', $this->payer($reference)->commande->statut);
        self::assertCount(10, $this->compteurs->cles[self::CLIENT]);
    }

    public function testUnPaiementSuspectPasseEnAVerifierSansVendreLesCles(): void
    {
        $this->paiements->compteCreeLe = $this->horloge->now()->modify('-2 hours');   // compte de 2 h, 151 € > 150 €
        $reference = $this->commander(1, self::OFFRE_CHERE);

        $paiement = $this->payer($reference);

        self::assertSame('A_VERIFIER', $paiement->commande->statut, 'un paiement accepté n\'est pas une commande payée');
        self::assertSame(['ACCEPTE'], $this->paiements->statuts(), 'le paiement est bien accepté');
        self::assertSame(['PAIEMENT_ACCEPTE', 'COMMANDE_A_VERIFIER'], array_column($this->audit->entrees, 'action'));
        self::assertSame('commande', $this->audit->entrees[1]['ressource']);
        self::assertCount(1, $this->compteurs->cles[self::CLIENT], 'les clés comptent dans le plafond des 24 h');
    }

    public function testUnePremiereCommandeAuDessusDe200EurosEstAVerifier(): void
    {
        $reference = $this->commander(2, self::OFFRE_CHERE);   // 302 €, compte ancien, première commande

        self::assertSame('A_VERIFIER', $this->payer($reference)->commande->statut);
    }

    public function testUneCommandeOrdinaireNEstPasAVerifier(): void
    {
        $this->paiements->premiereCommande = false;
        $reference = $this->commander(2, self::OFFRE_CHERE);   // 302 €, mais compte ancien et pas la première commande

        self::assertSame('PAYEE', $this->payer($reference)->commande->statut);
    }

    // ---- Fail Securely : Redis injoignable ----------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function pannes(): iterable
    {
        yield 'compteurs anti-fraude' => ['compteurs'];
        yield 'idempotence' => ['idempotence'];
    }

    #[DataProvider('pannes')]
    public function testRedisInjoignableRefuseLePaiementAvantTouteDebit(string $panne): void
    {
        $reference = $this->commander();
        match ($panne) {
            'compteurs' => $this->compteurs->indisponible = true,
            'idempotence' => $this->idempotence->indisponible = true,
        };

        try {
            $this->payer($reference);
            self::fail('Sans contrôle anti-fraude, le paiement doit être refusé.');
        } catch (PaiementIndisponibleException $e) {
            self::assertSame(503, $e->codeHttp());
            self::assertSame(['Retry-After' => '30'], $e->entetes());
        }
        self::assertSame([], $this->psp->demandes, 'aucun débit sans contrôle');
        self::assertSame([], $this->paiements->paiements);
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->commandes->commandes[$reference]['statut']);
    }

    public function testUneEcritureRedisPerdueApresLeDebitNeDefaitPasUnPaiementAccepte(): void
    {
        $this->compteurs->ecrituresIndisponibles = true;
        $reference = $this->commander();

        $paiement = $this->payer($reference);

        self::assertSame('PAYEE', $paiement->commande->statut, 'la base fait foi');
        self::assertStringContainsString('"etape":"compteurs anti-fraude"', implode("\n", $this->logs));
    }

    public function testUnEchecNonCompteParPanneRedisRenvoieQuandMemeLeRefus(): void
    {
        $this->compteurs->ecrituresIndisponibles = true;
        $reference = $this->commander();

        try {
            $this->payer($reference, self::REFUSEE);
            self::fail('Le refus aurait dû être signalé.');
        } catch (PaiementRefuseException $e) {
            self::assertSame(['code' => 'carte_refusee'], $e->details(), 'compteur inconnu : aucune promesse sur les essais restants');
        }
    }

    // ---- Entrées invalides, limitation de débit -----------------------------------------------------------------

    public function testUneCarteInvalideEstRefuseeSansRienCompter(): void
    {
        $reference = $this->commander();

        foreach ([['4242424242424241', '12/30', '123'], [self::OK, '01/20', '123'], [self::OK, '12/30', '12']] as [$carte, $expiration, $cvc]) {
            try {
                $this->service->payer($this->compte(), $reference, $carte, $expiration, $cvc, '0123456789abcdef0123', self::IP);
                self::fail('Une carte invalide aurait dû être refusée.');
            } catch (PaiementInvalideException $e) {
                self::assertSame(422, $e->codeHttp());
            }
        }
        self::assertSame([], $this->psp->demandes);
        self::assertSame([], $this->limiteur->compteurs, 'une faute de frappe ne consomme pas de tentative');
        self::assertSame([], $this->compteurs->echecs, 'et ne compte pas comme un échec de paiement');
        self::assertSame([], $this->idempotence->cles);
    }

    public function testLaCleDIdempotenceEstObligatoire(): void
    {
        $reference = $this->commander();

        foreach ([null, '', 'trop-court'] as $cle) {
            try {
                $this->service->payer($this->compte(), $reference, self::OK, '12/30', '123', $cle, self::IP);
                self::fail('La clé aurait dû être exigée.');
            } catch (PaiementInvalideException $e) {
                self::assertArrayHasKey('Idempotency-Key', $e->erreursChamps());
            }
        }
        self::assertSame([], $this->psp->demandes);
    }

    public function testDixTentativesParHeureAuPlus(): void
    {
        $reference = $this->commander();
        for ($i = 0; $i < PaiementService::TENTATIVES_PAR_HEURE; ++$i) {
            try {
                $this->payer($reference, self::REFUSEE);
            } catch (PaiementRefuseException|PaiementBloqueException) {
                // les échecs successifs bloquent aussi : on ne teste ici que le compteur de tentatives
                $this->compteurs->blocages = [];
            }
        }

        $this->expectException(TropDeTentativesException::class);
        $this->payer($reference, self::OK);
    }

    // ---- Aucune donnée de carte ---------------------------------------------------------------------------------

    public function testAucuneDonneeDeCarteNeSortDuServiceNiNEstTracee(): void
    {
        $reference = $this->commander();
        try {
            $this->payer($reference, self::REFUSEE);
        } catch (PaiementRefuseException) {
        }
        $this->psp->exception = new \RuntimeException('boom');
        try {
            $this->payer($reference, self::OK);
        } catch (PaiementRefuseException) {
        }
        $this->psp->exception = null;
        $this->payer($reference, self::OK);

        $traces = json_encode([$this->audit->entrees, $this->logs, $this->paiements->paiements, $this->compteurs->cles, $this->compteurs->echecs], \JSON_THROW_ON_ERROR);
        foreach ([self::OK, self::REFUSEE, '12/30'] as $secret) {   // le cryptogramme, 3 chiffres, pourrait coïncider avec un hexadécimal d'empreinte
            self::assertStringNotContainsString($secret, $traces, 'donnée de carte « '.$secret.' » dans les traces');
        }
        foreach ($this->paiements->paiements as $paiement) {
            self::assertSame(['statut', 'code', 'derniers', 'cle', 'reference', 'utilisateur', 'montant'], array_keys($paiement));
            self::assertMatchesRegularExpression('/^[0-9]{4}$/', (string) $paiement['derniers']);
        }
    }
}
