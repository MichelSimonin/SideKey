<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Tests\Support\Cles\ClesWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/** US-34 de bout en bout : seul l'acheteur d'une commande payée révèle sa clé, chaque affichage est journalisé. */
#[Group('database')]
#[Group('redis')]
final class CleControllerTest extends ClesWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.81';
    }

    public function testLAcheteurRevelesaCle(): void
    {
        $acheteur = $this->creerAcheteur();
        $valeur = self::nouvelleCle();
        $cleId = $this->commandeAvecCle($acheteur['id'], 'PAYEE', $valeur);

        $this->reveler($cleId, $this->jetonPour($acheteur['email']));

        $etat = $this->etat();
        self::assertSame(200, $etat['statut']);
        self::assertSame($cleId, $etat['corps']['id']);
        self::assertSame($valeur, $etat['corps']['cle']);
        self::assertNotEmpty($etat['corps']['reveleeLe']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $ligne = $this->connexion->fetchAssociative('SELECT revelee_le IS NOT NULL AS revelee FROM cle_licence WHERE id = CAST(:id AS uuid)', ['id' => $cleId]);
        self::assertSame(['revelee' => true], $ligne, 'première révélation datée (RG-20)');
        $journal = $this->connexion->fetchAllAssociative(
            "SELECT utilisateur_id::text AS u, octet_length(ip_hachee) AS ip FROM journal_audit WHERE action = 'REVELATION_CLE' AND ressource_id = CAST(:id AS uuid)",
            ['id' => $cleId],
        );
        self::assertSame([['u' => $acheteur['id'], 'ip' => 32]], $journal, 'adresse IP hachée, jamais en clair');
    }

    public function testChaqueAffichageEstJournaliseEtLaDateNeChangePas(): void
    {
        $acheteur = $this->creerAcheteur();
        $cleId = $this->commandeAvecCle($acheteur['id'], 'PAYEE', self::nouvelleCle());
        $jeton = $this->jetonPour($acheteur['email']);

        $this->reveler($cleId, $jeton);
        $premiere = $this->etat()['corps']['reveleeLe'];
        $this->reveler($cleId, $jeton);

        self::assertSame($premiere, $this->etat()['corps']['reveleeLe']);
        self::assertSame(2, $this->connexion->fetchOne("SELECT count(*) FROM journal_audit WHERE action = 'REVELATION_CLE' AND ressource_id = CAST(:id AS uuid)", ['id' => $cleId]));
    }

    public function testLaCleDUnAutreClientRepondCommeUneCleInexistante(): void
    {
        $cleId = $this->commandeAvecCle($this->creerAcheteur()['id'], 'PAYEE', self::nouvelleCle());
        $intrus = $this->creerAcheteur();

        $this->reveler($cleId, $this->jetonPour($intrus['email']));
        $autrui = $this->etat();
        $this->reveler('0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b', $this->jetonPour($intrus['email']));
        $inexistante = $this->etat();

        self::assertSame(404, $autrui['statut']);
        self::assertSame($inexistante, $autrui, 'aucune différence observable : pas d\'énumération (IDOR)');
        self::assertSame(0, $this->connexion->fetchOne("SELECT count(*) FROM journal_audit WHERE action = 'REVELATION_CLE' AND ressource_id = CAST(:id AS uuid)", ['id' => $cleId]));
    }

    public function testCommandeAVerifierLaCleNEstPasEncoreRevelable(): void
    {
        $acheteur = $this->creerAcheteur();
        $cleId = $this->commandeAvecCle($acheteur['id'], 'A_VERIFIER', self::nouvelleCle());

        $this->reveler($cleId, $this->jetonPour($acheteur['email']));

        self::assertSame(409, $this->etat()['statut']);
        self::assertSame(['message' => 'Cette clé sera disponible dès que le paiement de la commande sera confirmé.'], $this->etat()['corps']);
    }

    public function testCommandeRembourseeRienNEstRevele(): void
    {
        $acheteur = $this->creerAcheteur();
        $cleId = $this->commandeAvecCle($acheteur['id'], 'REMBOURSEE', self::nouvelleCle());

        $this->reveler($cleId, $this->jetonPour($acheteur['email']));

        self::assertSame(409, $this->etat()['statut']);
    }

    public function testLeBackOfficeNePeutPasReveler(): void
    {
        $cleId = $this->commandeAvecCle($this->creerAcheteur()['id'], 'PAYEE', self::nouvelleCle());

        foreach (['gestionnaire@sidekey.test', 'admin@sidekey.test', 'moderateur@sidekey.test'] as $email) {
            $this->reveler($cleId, $this->jetonPour($email));
            self::assertSame(403, $this->etat()['statut'], $email);
        }
    }

    public function testVisiteurNonAuthentifie(): void
    {
        $this->reveler($this->commandeAvecCle($this->creerAcheteur()['id'], 'PAYEE', self::nouvelleCle()), null);

        self::assertSame(401, $this->etat()['statut']);
    }

    public function testGetRefuse(): void
    {
        $acheteur = $this->creerAcheteur();
        $cleId = $this->commandeAvecCle($acheteur['id'], 'PAYEE', self::nouvelleCle());

        $this->json('GET', '/api/cles/'.$cleId.'/revelation', jeton: $this->jetonPour($acheteur['email']));

        self::assertSame(405, $this->etat()['statut']);
    }

    public function testChiffreAltereErreurGeneriqueSansRevelation(): void
    {
        $acheteur = $this->creerAcheteur();
        $cleId = $this->commandeAvecCle($acheteur['id'], 'PAYEE', self::nouvelleCle(), chiffreForce: "\x01".random_bytes(48));

        $this->reveler($cleId, $this->jetonPour($acheteur['email']));

        $etat = $this->etat();
        self::assertSame(500, $etat['statut']);
        self::assertSame(['message', 'correlationId'], array_keys($etat['corps']), 'aucun détail technique');
        self::assertSame(['revelee' => false], $this->connexion->fetchAssociative('SELECT revelee_le IS NOT NULL AS revelee FROM cle_licence WHERE id = CAST(:id AS uuid)', ['id' => $cleId]));
    }
}
