<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Service\Paiement\ConfigurationFraude;
use App\Tests\Support\Paiement\PaiementWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * US-32, US-33, US-36, RG-14 de bout en bout : API, PostgreSQL (index des paiements actifs, fn_valider_commande, triggers),
 * Redis (compteurs, idempotence, minuteur), prestataire simulé.
 */
#[Group('database')]
#[Group('redis')]
final class PaiementControllerTest extends PaiementWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.93';
    }

    // ---- Paiement accepté ---------------------------------------------------------------------------------------

    public function testUnPaiementAccepteVendLesClesPasseLaCommandeEnPayeeEtLesRend(): void
    {
        $acheteur = $this->acheteur();
        $offre = $this->offreEnStock('19.99', 3);
        $reference = $this->commander($acheteur, $offre, 2);
        $commandeId = (string) $this->connexion->fetchOne('SELECT id::text FROM commande WHERE reference = CAST(:r AS uuid)', ['r' => $reference]);
        self::assertGreaterThan(0, $this->redis->ttl('resa:'.$commandeId), 'minuteur de réservation en cours');

        $reponse = $this->payer($reference, $acheteur, cle: 'cle-de-test-0123456789');

        self::assertSame(200, $reponse['statut']);
        self::assertSame('PAYEE', $reponse['corps']['commande']['statut']);
        self::assertSame('39.98', $reponse['corps']['commande']['total']);
        self::assertNotNull($reponse['corps']['commande']['payeeLe']);
        self::assertSame(['statut' => 'ACCEPTE', 'derniersChiffres' => '4242', 'rejeu' => false], $reponse['corps']['paiement']);
        self::assertCount(2, $reponse['corps']['commande']['lignes'][0]['cles'], 'les clés sont listées, sans leur valeur');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertFalse($this->client->getResponse()->headers->has('Idempotent-Replayed'));

        self::assertSame('PAYEE', $this->statutCommande($reference));
        self::assertSame(2, $this->clesPar($reference, 'VENDUE'));
        $paiement = $this->connexion->fetchAssociative(
            "SELECT p.montant::text AS montant, p.statut, p.code_retour, p.derniers_chiffres, p.cle_idempotence FROM paiement p JOIN commande c ON c.id = p.commande_id WHERE c.reference = CAST(:r AS uuid)",
            ['r' => $reference],
        );
        self::assertIsArray($paiement);
        self::assertSame(['39.98', 'ACCEPTE', 'ok', '4242', hash('sha256', $acheteur['id']."\ncle-de-test-0123456789")], array_values($paiement), 'montant relu en base ; clé stockée = empreinte du client et de sa clé');
        $historique = $this->connexion->fetchAssociative("SELECT ancien_statut, auteur_id::text AS auteur FROM commande_historique WHERE commande_id = CAST(:c AS uuid) AND nouveau_statut = 'PAYEE'", ['c' => $commandeId]);
        self::assertSame(['EN_ATTENTE_PAIEMENT', $acheteur['id']], array_values((array) $historique), 'RG-21');

        self::assertSame(2, $this->redis->zCard('fraud:keys24h:'.$acheteur['id']), '2 clés comptées pour le plafond de 24 h');
        self::assertSame(-2, $this->redis->ttl('resa:'.$commandeId), 'minuteur arrêté');
        $audit = $this->connexion->fetchAssociative("SELECT ressource, utilisateur_id::text AS u FROM journal_audit WHERE action = 'PAIEMENT_ACCEPTE' AND utilisateur_id = CAST(:u AS uuid)", ['u' => $acheteur['id']]);
        self::assertSame(['paiement', $acheteur['id']], array_values((array) $audit));

        // US-33/US-34 : l'acheteur peut maintenant révéler ses clés (F-08)
        $this->reveler((string) $reponse['corps']['commande']['lignes'][0]['cles'][0]['id'], $acheteur['jeton']);
        self::assertSame(200, $this->etat()['statut']);
    }

    public function testRejouerLaMemeCleNeDebitePasDeuxFois(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock(), 2);

        $premier = $this->payer($reference, $acheteur, cle: 'cle-de-rejeu-0123456789');
        $rejeu = $this->payer($reference, $acheteur, cle: 'cle-de-rejeu-0123456789');

        self::assertSame(200, $rejeu['statut']);
        self::assertSame('true', $this->client->getResponse()->headers->get('Idempotent-Replayed'));
        self::assertTrue($rejeu['corps']['paiement']['rejeu']);
        self::assertSame($premier['corps']['commande']['reference'], $rejeu['corps']['commande']['reference']);
        self::assertSame('PAYEE', $rejeu['corps']['commande']['statut']);
        self::assertSame(['ACCEPTE'], $this->statutsPaiements($reference), 'un seul paiement');
        self::assertSame(2, $this->redis->zCard('fraud:keys24h:'.$acheteur['id']), 'les clés ne sont pas comptées deux fois');
        self::assertSame(2, $this->clesPar($reference, 'VENDUE'));
    }

    public function testUneCommandeDejaPayeeRefuseUnSecondPaiementAvecUneNouvelleCle(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());
        $this->payer($reference, $acheteur);

        $second = $this->payer($reference, $acheteur);

        self::assertSame(409, $second['statut']);
        self::assertSame('Cette commande ne peut plus être payée : elle a déjà été payée, est en cours de vérification, a expiré ou a été annulée.', $second['corps']['message']);
        self::assertSame(['ACCEPTE'], $this->statutsPaiements($reference), 'aucun second débit');
    }

    public function testLeMontantVientDeLaBaseJamaisDeLaRequete(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock('59.99', 1));

        $reponse = $this->payer($reference, $acheteur, $this->carte(self::OK) + ['montant' => '0.01', 'total' => '0.01', 'commandeId' => 'x', 'statut' => 'PAYEE', 'derniersChiffres' => '0000']);

        self::assertSame(200, $reponse['statut']);
        self::assertSame('59.99', $this->connexion->fetchOne('SELECT p.montant::text FROM paiement p JOIN commande c ON c.id = p.commande_id WHERE c.reference = CAST(:r AS uuid)', ['r' => $reference]));
        self::assertSame('4242', $reponse['corps']['paiement']['derniersChiffres'], 'les derniers chiffres viennent de la carte, pas du corps');
    }

    // ---- Cartes de test, refus, nouvel essai --------------------------------------------------------------------

    /** @return iterable<string, array{string, string, string, string}> */
    public static function refus(): iterable
    {
        yield 'carte refusée' => [self::REFUSEE, 'carte_refusee', 'REFUSE', 'Paiement refusé par la banque. Essayez une autre carte.'];
        yield 'fonds insuffisants' => [self::FONDS, 'fonds_insuffisants', 'REFUSE', 'Paiement refusé : fonds insuffisants.'];
        yield 'délai dépassé' => [self::DELAI, 'delai_depasse', 'ERREUR', "La banque n'a pas répondu à temps. Aucun débit n'a été effectué : réessayez."];
        yield 'carte inconnue' => ['5555555555554444', 'carte_inconnue', 'REFUSE', 'Paiement refusé : carte non reconnue.'];
    }

    #[DataProvider('refus')]
    public function testUnRefusRenvoie402ConserveLaReservationEtCompteLEchec(string $carte, string $code, string $statut, string $message): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock(), 2);

        $reponse = $this->payer($reference, $acheteur, $this->carte($carte));

        self::assertSame(402, $reponse['statut']);
        self::assertSame(['message' => $message, 'code' => $code, 'tentativesRestantes' => 2], $reponse['corps']);
        self::assertSame([$statut], $this->statutsPaiements($reference));
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->statutCommande($reference), 'US-36 : la réservation est conservée');
        self::assertSame(2, $this->clesPar($reference, 'RESERVEE'));
        self::assertSame(0, $this->redis->exists('fraud:keys24h:'.$acheteur['id']), 'un refus ne compte aucune clé achetée');
        self::assertSame('1', $this->redis->get('fraud:fails:'.$acheteur['id']));
        self::assertSame('4242' === substr($carte, -4) ? '4242' : substr($carte, -4), $this->connexion->fetchOne('SELECT p.derniers_chiffres FROM paiement p JOIN commande c ON c.id = p.commande_id WHERE c.reference = CAST(:r AS uuid)', ['r' => $reference]));
    }

    public function testLeMessageDeRefusEstTraduit(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());

        $reponse = $this->payer($reference, $acheteur, $this->carte(self::FONDS), langue: 'en');

        self::assertSame('Payment declined: insufficient funds.', $reponse['corps']['message']);
    }

    public function testApresUnRefusLeClientReessaieAvecUneNouvelleCleEtUneAutreCarte(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());
        self::assertSame(402, $this->payer($reference, $acheteur, $this->carte(self::REFUSEE))['statut']);

        $reponse = $this->payer($reference, $acheteur, $this->carte(self::OK));

        self::assertSame(200, $reponse['statut']);
        self::assertSame(['REFUSE', 'ACCEPTE'], $this->statutsPaiements($reference));
        self::assertSame('PAYEE', $this->statutCommande($reference));
        self::assertSame(0, $this->redis->exists('fraud:fails:'.$acheteur['id']), 'un paiement accepté remet les échecs à zéro');
    }

    public function testRejouerUnRefusRenvoieLeMemeRefusSansRappelerLePrestataire(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());
        $premier = $this->payer($reference, $acheteur, $this->carte(self::REFUSEE), 'cle-de-refus-0123456789');

        $rejeu = $this->payer($reference, $acheteur, $this->carte(self::OK), 'cle-de-refus-0123456789');   // même clé : un rejeu, pas un nouvel essai

        self::assertSame(402, $rejeu['statut']);
        self::assertSame('carte_refusee', $rejeu['corps']['code']);
        self::assertSame(['REFUSE'], $this->statutsPaiements($reference));
        self::assertSame('1', $this->redis->get('fraud:fails:'.$acheteur['id']), 'un rejeu ne compte pas un second échec');
        self::assertSame(402, $premier['statut']);
    }

    // ---- Blocage après 3 échecs ---------------------------------------------------------------------------------

    public function testLeTroisiemeEchecBloqueLeClientUneHeureSurTousSesPaiements(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());

        $restantes = [];
        for ($i = 0; $i < 3; ++$i) {
            $restantes[] = $this->payer($reference, $acheteur, $this->carte(self::REFUSEE))['corps']['tentativesRestantes'];
        }
        self::assertSame([2, 1, 0], $restantes);

        $bloque = $this->payer($reference, $acheteur, $this->carte(self::OK));   // même avec la bonne carte
        self::assertSame(429, $bloque['statut']);
        self::assertSame('Trop de paiements refusés. Réessayez dans 60 min.', $bloque['corps']['message']);
        $retry = (int) $this->client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThan(3_580, $retry);
        self::assertLessThanOrEqual(3_600, $retry);
        self::assertSame(['REFUSE', 'REFUSE', 'REFUSE'], $this->statutsPaiements($reference), 'le prestataire n\'a pas été rappelé');
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->statutCommande($reference));
        self::assertSame(1, $this->connexion->fetchOne("SELECT count(*) FROM journal_audit WHERE action = 'PAIEMENT_BLOQUE' AND utilisateur_id = CAST(:u AS uuid)", ['u' => $acheteur['id']]));

        // Le blocage ne concerne que ce client
        $autre = $this->acheteur();
        $referenceAutre = $this->commander($autre, $this->offreEnStock());
        self::assertSame(200, $this->payer($referenceAutre, $autre)['statut']);
    }

    // ---- RG-14 : plafond de clés sur 24 h glissantes ------------------------------------------------------------

    public function testLePlafondDeDixClesSurVingtQuatreHeuresRefuseAvantTouteDebit(): void
    {
        $acheteur = $this->acheteur();
        $maintenant = time();
        for ($i = 1; $i <= 8; ++$i) {
            $this->redis->zAdd('fraud:keys24h:'.$acheteur['id'], $maintenant - 3_600, 'ancienne:'.$i);   // 8 clés payées il y a 1 h
        }
        $reference = $this->commander($acheteur, $this->offreEnStock(), 3);   // 8 + 3 = 11 > 10

        $reponse = $this->payer($reference, $acheteur);

        self::assertSame(422, $reponse['statut']);
        self::assertSame('Vous avez atteint la limite de 10 clés par 24 h. Cette commande ne peut pas être payée maintenant ; vous pouvez l\'annuler.', $reponse['corps']['message']);
        self::assertSame([], $this->statutsPaiements($reference), 'refusé AVANT le prestataire : aucun paiement, aucun débit');
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->statutCommande($reference));
        self::assertSame(0, $this->redis->exists('fraud:fails:'.$acheteur['id']), 'ce n\'est pas un échec de paiement');

        // La commande reste annulable : les clés retournent en stock
        $this->json('POST', '/api/commandes/'.$reference.'/annulation', jeton: $acheteur['jeton']);
        self::assertSame(200, $this->etat()['statut']);
    }

    public function testLaFenetreEstGlissanteLesClesDeMoinsDe24hSeulesComptent(): void
    {
        $acheteur = $this->acheteur();
        $maintenant = time();
        for ($i = 1; $i <= 8; ++$i) {
            $this->redis->zAdd('fraud:keys24h:'.$acheteur['id'], $maintenant - 86_400 - 60, 'ancienne:'.$i);   // 24 h et 1 min : sorties de la fenêtre
        }
        $reference = $this->commander($acheteur, $this->offreEnStock(), 3);

        self::assertSame(200, $this->payer($reference, $acheteur)['statut']);
        self::assertSame(3, $this->redis->zCard('fraud:keys24h:'.$acheteur['id']), 'les anciennes entrées sont nettoyées');
    }

    public function testExactementDixClesSontAutorisees(): void
    {
        $acheteur = $this->acheteur();
        $maintenant = time();
        for ($i = 1; $i <= 7; ++$i) {
            $this->redis->zAdd('fraud:keys24h:'.$acheteur['id'], $maintenant - 7_200, 'ancienne:'.$i);
        }
        $reference = $this->commander($acheteur, $this->offreEnStock(), 3);   // 7 + 3 = 10

        self::assertSame(200, $this->payer($reference, $acheteur)['statut']);
        self::assertSame(10, $this->redis->zCard('fraud:keys24h:'.$acheteur['id']));
    }

    // ---- RG-14 : commande à vérifier ----------------------------------------------------------------------------

    public function testUnCompteRecentQuiPaieAuDessusDe150EurosPasseEnAVerifier(): void
    {
        $acheteur = $this->acheteur(ancien: false);   // compte créé à l'instant
        $reference = $this->commander($acheteur, $this->offreEnStock('160.00', 2), 1);
        $commandeId = (string) $this->connexion->fetchOne('SELECT id::text FROM commande WHERE reference = CAST(:r AS uuid)', ['r' => $reference]);

        $reponse = $this->payer($reference, $acheteur);

        self::assertSame(200, $reponse['statut']);
        self::assertSame('A_VERIFIER', $reponse['corps']['commande']['statut'], 'un paiement accepté n\'est pas toujours une commande payée');
        self::assertSame('ACCEPTE', $reponse['corps']['paiement']['statut']);
        self::assertNull($reponse['corps']['commande']['payeeLe']);
        self::assertSame([], $reponse['corps']['commande']['lignes'][0]['cles'], 'aucune clé n\'est promise tant que la commande n\'est pas payée');
        self::assertStringNotContainsStringIgnoringCase('fraud', json_encode($reponse['corps'], \JSON_THROW_ON_ERROR), 'la règle n\'est pas révélée');

        self::assertSame('A_VERIFIER', $this->statutCommande($reference));
        self::assertSame(['ACCEPTE'], $this->statutsPaiements($reference));
        self::assertSame(1, $this->clesPar($reference, 'RESERVEE'), 'clés conservées, non vendues');
        self::assertSame(0, $this->clesPar($reference, 'VENDUE'));
        $historique = $this->connexion->fetchAssociative("SELECT ancien_statut, auteur_id::text AS auteur FROM commande_historique WHERE commande_id = CAST(:c AS uuid) AND nouveau_statut = 'A_VERIFIER'", ['c' => $commandeId]);
        self::assertSame(['EN_ATTENTE_PAIEMENT', $acheteur['id']], array_values((array) $historique));
        self::assertSame(['COMMANDE_A_VERIFIER', 'PAIEMENT_ACCEPTE'], array_map('strval', $this->connexion->fetchFirstColumn("SELECT action FROM journal_audit WHERE utilisateur_id = CAST(:u AS uuid) AND action IN ('PAIEMENT_ACCEPTE', 'COMMANDE_A_VERIFIER') ORDER BY action", ['u' => $acheteur['id']])));
        self::assertSame(1, $this->redis->zCard('fraud:keys24h:'.$acheteur['id']), 'les clés comptent dans le plafond des 24 h');

        // Les clés ne sont pas révélables (F-08) : la commande n'est pas PAYEE
        $cleId = (string) $this->connexion->fetchOne("SELECT k.id::text FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id WHERE l.commande_id = CAST(:c AS uuid)", ['c' => $commandeId]);
        $this->reveler($cleId, $acheteur['jeton']);
        self::assertSame(409, $this->etat()['statut']);

        // Un second paiement de cette commande est refusé
        self::assertSame(409, $this->payer($reference, $acheteur)['statut']);
    }

    public function testUnComptePlusAncienPaieLeMemeMontantSansVerification(): void
    {
        $acheteur = $this->acheteur();
        $this->connexion->executeStatement("INSERT INTO commande (statut, montant_total, confirme_majeur, payee_le, expire_le, utilisateur_id, langue_code) VALUES ('PAYEE', 10, true, now(), now() + interval '1 minute', CAST(:u AS uuid), 'fr')", ['u' => $acheteur['id']]);
        $reference = $this->commander($acheteur, $this->offreEnStock('160.00', 2), 1);   // compte de 30 jours, pas la première commande

        self::assertSame('PAYEE', $this->payer($reference, $acheteur)['corps']['commande']['statut']);
    }

    public function testUnePremiereCommandeAuDessusDe200EurosEstAVerifierPasLaSuivante(): void
    {
        $acheteur = $this->acheteur();   // compte ancien : seule la règle de la première commande s'applique
        $offre = $this->offreEnStock('210.00', 4);
        $premiere = $this->commander($acheteur, $offre, 1);

        self::assertSame('A_VERIFIER', $this->payer($premiere, $acheteur)['corps']['commande']['statut']);

        // La première est maintenant « à vérifier » : la suivante n'est plus une première commande
        $seconde = $this->commander($acheteur, $offre, 1);
        self::assertSame('PAYEE', $this->payer($seconde, $acheteur)['corps']['commande']['statut']);
    }

    public function testUnePremiereCommandeDe200EurosPileNEstPasAVerifier(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock('200.00', 2), 1);

        self::assertSame('PAYEE', $this->payer($reference, $acheteur)['corps']['commande']['statut'], '« dépasse 200 € » : 200,00 € pile ne dépasse pas');
    }

    // ---- Commande introuvable, expirée, autrui ------------------------------------------------------------------

    public function testLaCommandeDAutruiRepondCommeUneCommandeInexistanteSansRienEcrire(): void
    {
        $lea = $this->acheteur();
        $marcus = $this->acheteur();
        $reference = $this->commander($lea, $this->offreEnStock());

        $autrui = $this->payer($reference, $marcus, cle: 'cle-de-marcus-0123456789');
        $inconnue = $this->payer('0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b', $marcus, cle: 'cle-de-marcus-0123456789');

        self::assertSame(404, $autrui['statut']);
        self::assertSame($inconnue, $autrui, 'aucune différence observable (IDOR)');
        self::assertSame([], $this->statutsPaiements($reference));
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->statutCommande($reference));
        self::assertSame(0, $this->redis->exists('idem:'.hash('sha256', $marcus['id']."\ncle-de-marcus-0123456789")), 'aucune clé d\'idempotence réservée');
    }

    public function testLaCleDUnClientNeDonneAucunAccesAuxPaiementsDUnAutre(): void
    {
        $lea = $this->acheteur();
        $marcus = $this->acheteur();
        $referenceLea = $this->commander($lea, $this->offreEnStock());
        $this->payer($referenceLea, $lea, cle: 'meme-cle-0123456789abcdef');
        $referenceMarcus = $this->commander($marcus, $this->offreEnStock());

        // Marcus choisit par hasard (ou par malice) la même valeur de clé : aucune collision, aucun rejeu de la réponse de Léa
        $reponse = $this->payer($referenceMarcus, $marcus, cle: 'meme-cle-0123456789abcdef');

        self::assertSame(200, $reponse['statut']);
        self::assertFalse($reponse['corps']['paiement']['rejeu']);
        self::assertSame($referenceMarcus, $reponse['corps']['commande']['reference']);
    }

    public function testUneReservationExpireeOuSurLePointDExpirerNEstPasPayee(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());

        $this->connexion->executeStatement("UPDATE commande SET expire_le = now() + interval '20 seconds' WHERE reference = CAST(:r AS uuid)", ['r' => $reference]);
        self::assertSame(409, $this->payer($reference, $acheteur)['statut'], 'moins de 30 s : le débit précéderait une validation vouée à l\'échec');

        $this->connexion->executeStatement("UPDATE commande SET cree_le = now() - interval '2 hours', expire_le = now() - interval '1 minute' WHERE reference = CAST(:r AS uuid)", ['r' => $reference]);
        self::assertSame(409, $this->payer($reference, $acheteur)['statut']);
        self::assertSame([], $this->statutsPaiements($reference));
    }

    // ---- Accès --------------------------------------------------------------------------------------------------

    public function testVisiteurEtBackOfficeSontRefuses(): void
    {
        $uri = '/api/commandes/0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b/paiement';
        $corps = $this->carte(self::OK);

        $this->json('POST', $uri, $corps, serveur: ['HTTP_IDEMPOTENCY_KEY' => 'cle-de-test-0123456789']);
        self::assertSame(401, $this->etat()['statut']);

        foreach (['gestionnaire@sidekey.test', 'admin@sidekey.test', 'moderateur@sidekey.test'] as $email) {
            $this->json('POST', $uri, $corps, $this->jetonPour($email), ['HTTP_IDEMPOTENCY_KEY' => 'cle-de-test-0123456789']);
            self::assertSame(403, $this->etat()['statut'], $email);
        }
    }

    public function testUneReferenceMalFormeeEstIntrouvable(): void
    {
        $acheteur = $this->acheteur();

        foreach (["1'%20OR%20'1'='1", 'pas-un-uuid', '..%2F..%2Fetc%2Fpasswd'] as $reference) {
            $this->json('POST', '/api/commandes/'.$reference.'/paiement', $this->carte(self::OK), $acheteur['jeton'], ['HTTP_IDEMPOTENCY_KEY' => 'cle-de-test-0123456789']);
            self::assertSame(404, $this->etat()['statut'], $reference);
        }
    }

    // ---- Validation ---------------------------------------------------------------------------------------------

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function cartesInvalides(): iterable
    {
        yield 'Luhn faux' => [['numeroCarte' => '4242424242424241', 'expiration' => '12/30', 'cryptogramme' => '123'], 'numeroCarte'];
        yield 'numéro avec lettres' => [['numeroCarte' => '4242abcd42424242', 'expiration' => '12/30', 'cryptogramme' => '123'], 'numeroCarte'];
        yield 'numéro vide' => [['numeroCarte' => '', 'expiration' => '12/30', 'cryptogramme' => '123'], 'numeroCarte'];
        yield 'expiration dépassée' => [['numeroCarte' => '4242424242424242', 'expiration' => '01/20', 'cryptogramme' => '123'], 'expiration'];
        yield 'expiration mal formée' => [['numeroCarte' => '4242424242424242', 'expiration' => '2030-12', 'cryptogramme' => '123'], 'expiration'];
        yield 'cryptogramme court' => [['numeroCarte' => '4242424242424242', 'expiration' => '12/30', 'cryptogramme' => '12'], 'cryptogramme'];
        yield 'injection SQL' => [['numeroCarte' => "4242424242424242'; DROP TABLE paiement;--", 'expiration' => '12/30', 'cryptogramme' => '123'], 'numeroCarte'];
    }

    /** @param array<string, string> $carte */
    #[DataProvider('cartesInvalides')]
    public function testUneCarteInvalideEstRefuseeParChampSansRienCompter(array $carte, string $champ): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());

        $reponse = $this->payer($reference, $acheteur, $carte);

        self::assertSame(422, $reponse['statut']);
        self::assertArrayHasKey($champ, $reponse['corps']['erreurs']);
        self::assertSame([], $this->statutsPaiements($reference), 'aucun paiement ouvert');
        self::assertSame(0, $this->redis->exists('fraud:fails:'.$acheteur['id']), 'une faute de frappe n\'est pas un échec de paiement');
        self::assertSame(0, $this->redis->exists('rl:pay:'.$acheteur['id']), 'ni une tentative décomptée');
        self::assertStringNotContainsString('DROP', json_encode($reponse['corps'], \JSON_THROW_ON_ERROR), 'la saisie n\'est jamais renvoyée');
    }

    public function testLesErreursDeCarteSontTraduites(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());

        $reponse = $this->payer($reference, $acheteur, ['numeroCarte' => '4242424242424241', 'expiration' => '01/20', 'cryptogramme' => '123'], langue: 'en');

        self::assertSame(422, $reponse['statut']);
        self::assertSame('Invalid card number.', $reponse['corps']['erreurs']['numeroCarte']);
        self::assertSame('Invalid or past expiry date (format MM/YY).', $reponse['corps']['erreurs']['expiration']);
    }

    public function testLaCleDIdempotenceEstObligatoire(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());

        foreach ([null, '', 'courte', str_repeat('a', 65), 'avec espace 0123456789'] as $cle) {
            $serveur = null === $cle ? [] : ['HTTP_IDEMPOTENCY_KEY' => $cle];
            $this->json('POST', '/api/commandes/'.$reference.'/paiement', $this->carte(self::OK), $acheteur['jeton'], $serveur);
            $etat = $this->etat();
            self::assertSame(422, $etat['statut'], (string) $cle);
            self::assertArrayHasKey('Idempotency-Key', $etat['corps']['erreurs']);
        }
        self::assertSame([], $this->statutsPaiements($reference));
    }

    public function testSeulLeJsonEstAccepteEtLesChampsManquantsSontRefuses(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());

        $this->client->request('POST', '/api/commandes/'.$reference.'/paiement', $this->carte(self::OK), server: ['HTTP_AUTHORIZATION' => 'Bearer '.$acheteur['jeton'], 'HTTP_IDEMPOTENCY_KEY' => 'cle-de-test-0123456789', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded']);
        self::assertContains($this->client->getResponse()->getStatusCode(), [400, 415, 422], 'un formulaire HTML forgé ne passe pas');

        $vide = $this->payer($reference, $acheteur, []);
        self::assertSame(422, $vide['statut']);
        self::assertSame([], $this->statutsPaiements($reference));
    }

    public function testUnLimiteDeDixTentativesParHeure(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());
        $this->redis->set('rl:pay:'.$acheteur['id'], '10', ['ex' => 3600]);

        $reponse = $this->payer($reference, $acheteur);

        self::assertSame(429, $reponse['statut']);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame([], $this->statutsPaiements($reference));
    }

    // ---- Configuration ------------------------------------------------------------------------------------------

    public function testLesSeuilsViennentDeLaConfigurationSymfony(): void
    {
        $configuration = static::getContainer()->get(ConfigurationFraude::class);

        self::assertInstanceOf(ConfigurationFraude::class, $configuration);
        self::assertSame([10, 3, 3600, 24, 150, 200], [
            $configuration->clesMax24h, $configuration->echecsMax, $configuration->blocageSecondes,
            $configuration->compteRecentHeures, $configuration->seuilCompteRecentEuros, $configuration->seuilPremiereCommandeEuros,
        ], 'niveau Équilibré, CDC §6.3');
    }
}
