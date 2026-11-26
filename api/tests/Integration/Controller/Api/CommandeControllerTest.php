<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use App\Tests\Support\Cles\ClesWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * US-30, US-31, US-33, US-35 de bout en bout : panier Redis (cookie), API, PostgreSQL (transaction,
 * fn_reserver_cles, fn_annuler_commande, triggers), minuteur Redis.
 */
#[Group('database')]
#[Group('redis')]
final class CommandeControllerTest extends ClesWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.90';
    }

    public function testPasserCommandeReserveLesClesEtVideLePanier(): void
    {
        $offre = $this->creerOffre(prix: '24.50');
        $this->stocker($offre, 3);
        $acheteur = $this->acheteurVerifie();
        $this->ajouterAuPanier($offre, 2);
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en');

        $this->passer($this->jetonPour($acheteur['email']));

        $etat = $this->etat();
        self::assertSame(201, $etat['statut']);
        $commande = $etat['corps'];
        self::assertSame('EN_ATTENTE_PAIEMENT', $commande['statut']);
        self::assertSame('49.00', $commande['total']);
        self::assertGreaterThan(890, $commande['secondesRestantes']);
        self::assertLessThanOrEqual(900, $commande['secondesRestantes']);
        self::assertNotNull($commande['expireLe']);
        self::assertCount(1, $commande['lignes']);
        self::assertSame([2, '24.50', '49.00', []], [$commande['lignes'][0]['quantite'], $commande['lignes'][0]['prixUnitaire'], $commande['lignes'][0]['sousTotal'], $commande['lignes'][0]['cles']]);
        self::assertArrayNotHasKey('id', $commande, 'seule la référence est exposée');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $enBase = $this->connexion->fetchAssociative(
            "SELECT c.id::text AS id, c.montant_total, c.langue_code, c.confirme_majeur, c.expire_le - c.cree_le AS duree,
                    (SELECT count(*) FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id WHERE l.commande_id = c.id AND k.statut = 'RESERVEE') AS reservees,
                    (SELECT auteur_id::text FROM commande_historique h WHERE h.commande_id = c.id) AS auteur
               FROM commande c WHERE c.reference = CAST(:r AS uuid)",
            ['r' => $commande['reference']],
        );
        self::assertIsArray($enBase);
        self::assertSame(['49.00', 'en', false, '00:15:00', 2, $acheteur['id']], [$enBase['montant_total'], $enBase['langue_code'], $enBase['confirme_majeur'], $enBase['duree'], $enBase['reservees'], $enBase['auteur']]);
        self::assertSame(1, $this->connexion->fetchOne("SELECT count(*) FROM cle_licence WHERE offre_id = CAST(:o AS uuid) AND statut = 'DISPONIBLE'", ['o' => $offre]));
        $ttl = $this->redis->ttl('resa:'.$enBase['id']);
        self::assertGreaterThan(890, $ttl, 'minuteur Redis de 15 min');

        $this->json('GET', '/api/panier');
        self::assertSame([], $this->etat()['corps']['lignes'], 'panier vidé');
    }

    public function testLePrixVientDeLaBaseJamaisDuClient(): void
    {
        $offre = $this->creerOffre(prix: '59.99');
        $this->stocker($offre, 1);
        $acheteur = $this->acheteurVerifie();
        $this->ajouterAuPanier($offre, 1);

        $this->json('POST', '/api/commandes', ['confirmeMajeur' => false, 'total' => '0.01', 'prixUnitaire' => '0.01', 'lignes' => [['offreId' => $offre, 'quantite' => 5, 'prix' => 0]]], $this->jetonPour($acheteur['email']));

        $etat = $this->etat();
        self::assertSame(201, $etat['statut']);
        self::assertSame('59.99', $etat['corps']['total'], 'les champs envoyés par le client sont ignorés (RG-11)');
        self::assertSame(1, $etat['corps']['lignes'][0]['quantite']);
    }

    public function testConsulterEtLister(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 1);
        $acheteur = $this->acheteurVerifie();
        $jeton = $this->jetonPour($acheteur['email']);
        $this->ajouterAuPanier($offre, 1);
        $this->passer($jeton);
        $reference = $this->etat()['corps']['reference'];

        $this->json('GET', '/api/commandes/'.strtoupper($reference), jeton: $jeton);
        self::assertSame(200, $this->etat()['statut']);
        self::assertSame($reference, $this->etat()['corps']['reference']);

        $this->json('GET', '/api/commandes', jeton: $jeton);
        self::assertSame([['reference' => $reference, 'statut' => 'EN_ATTENTE_PAIEMENT', 'total' => '19.99', 'articles' => 1]],
            array_map(static fn (array $c): array => array_diff_key($c, ['creeeLe' => true]), $this->etat()['corps']['commandes']));
    }

    public function testUneCommandePayeeListeSesClesSansLeurValeur(): void
    {
        $acheteur = $this->acheteurVerifie();
        $valeur = self::nouvelleCle();
        $cleId = $this->commandeAvecCle($acheteur['id'], 'PAYEE', $valeur);
        $reference = (string) $this->connexion->fetchOne('SELECT c.reference::text FROM commande c JOIN ligne_commande l ON l.commande_id = c.id JOIN cle_licence k ON k.ligne_commande_id = l.id WHERE k.id = CAST(:k AS uuid)', ['k' => $cleId]);
        $jeton = $this->jetonPour($acheteur['email']);

        $this->json('GET', '/api/commandes/'.$reference, jeton: $jeton);

        $corps = $this->etat()['corps'];
        self::assertSame('PAYEE', $corps['statut']);
        self::assertNull($corps['secondesRestantes']);
        self::assertNull($corps['expireLe'], 'l\'échéance ne concerne que l\'attente de paiement');
        self::assertSame([['id' => $cleId, 'revelee' => false, 'reveleeLe' => null]], $corps['lignes'][0]['cles']);
        self::assertStringNotContainsString($valeur, (string) $this->client->getResponse()->getContent());

        $this->reveler($cleId, $jeton);
        $this->json('GET', '/api/commandes/'.$reference, jeton: $jeton);
        self::assertTrue($this->etat()['corps']['lignes'][0]['cles'][0]['revelee']);
    }

    public function testUneSeuleCommandeEnAttenteALaFois(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 2);
        $acheteur = $this->acheteurVerifie();
        $jeton = $this->jetonPour($acheteur['email']);
        $this->ajouterAuPanier($offre, 1);
        $this->passer($jeton);
        $reference = $this->etat()['corps']['reference'];
        $this->ajouterAuPanier($offre, 1);

        $this->passer($jeton);

        self::assertSame(409, $this->etat()['statut']);
        self::assertSame($reference, $this->etat()['corps']['reference']);
        self::assertSame(1, $this->connexion->fetchOne("SELECT count(*) FROM cle_licence WHERE offre_id = CAST(:o AS uuid) AND statut = 'RESERVEE'", ['o' => $offre]));
    }

    public function testAnnulerRemetLesClesEnStock(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 2);
        $acheteur = $this->acheteurVerifie();
        $jeton = $this->jetonPour($acheteur['email']);
        $this->ajouterAuPanier($offre, 2);
        $this->passer($jeton);
        $reference = $this->etat()['corps']['reference'];
        $id = (string) $this->connexion->fetchOne('SELECT id::text FROM commande WHERE reference = CAST(:r AS uuid)', ['r' => $reference]);

        $this->json('POST', '/api/commandes/'.$reference.'/annulation', jeton: $jeton);

        self::assertSame(200, $this->etat()['statut']);
        self::assertSame('ANNULEE', $this->etat()['corps']['statut']);
        self::assertSame(2, $this->connexion->fetchOne("SELECT count(*) FROM cle_licence WHERE offre_id = CAST(:o AS uuid) AND statut = 'DISPONIBLE'", ['o' => $offre]));
        self::assertSame(-2, $this->redis->ttl('resa:'.$id), 'minuteur arrêté');
        self::assertSame($acheteur['id'], $this->connexion->fetchOne("SELECT auteur_id::text FROM commande_historique WHERE commande_id = CAST(:c AS uuid) AND nouveau_statut = 'ANNULEE'", ['c' => $id]));

        $this->json('POST', '/api/commandes/'.$reference.'/annulation', jeton: $jeton);
        self::assertSame(409, $this->etat()['statut']);
    }

    public function testCommandeExpireeParLaTachePlanifiee(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 1);
        $acheteur = $this->acheteurVerifie();
        $jeton = $this->jetonPour($acheteur['email']);
        $this->ajouterAuPanier($offre, 1);
        $this->passer($jeton);
        $reference = $this->etat()['corps']['reference'];
        // 15 minutes plus tard
        $this->connexion->executeStatement("UPDATE commande SET cree_le = cree_le - interval '16 minutes', expire_le = expire_le - interval '16 minutes' WHERE reference = CAST(:r AS uuid)", ['r' => $reference]);
        $this->connexion->executeStatement('SELECT fn_liberer_reservations_expirees()');

        $this->json('GET', '/api/commandes/'.$reference, jeton: $jeton);
        self::assertSame('EXPIREE', $this->etat()['corps']['statut']);
        self::assertSame(1, $this->connexion->fetchOne("SELECT count(*) FROM cle_licence WHERE offre_id = CAST(:o AS uuid) AND statut = 'DISPONIBLE'", ['o' => $offre]));

        $this->json('POST', '/api/commandes/'.$reference.'/annulation', jeton: $jeton);
        self::assertSame(409, $this->etat()['statut']);
        $this->ajouterAuPanier($offre, 1);
        $this->passer($jeton);
        self::assertSame(201, $this->etat()['statut'], 'la commande expirée ne bloque plus le client');
    }

    public function testPanierModifieEntreLAffichageEtLaCommande(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 2);
        $acheteur = $this->acheteurVerifie();
        $this->ajouterAuPanier($offre, 2);
        // Une clé part chez un autre client
        $this->commandeAvecCle($this->creerAcheteur()['id'], 'PAYEE', self::nouvelleCle());
        $this->connexion->executeStatement("UPDATE cle_licence SET statut = 'RESERVEE', reservee_le = now(), ligne_commande_id = (SELECT id FROM ligne_commande ORDER BY id LIMIT 1) WHERE id = (SELECT id FROM cle_licence WHERE offre_id = CAST(:o AS uuid) LIMIT 1)", ['o' => $offre]);

        $this->passer($this->jetonPour($acheteur['email']));

        self::assertSame(409, $this->etat()['statut']);
        self::assertSame(['message' => 'Votre panier a changé (stock ou disponibilité) : vérifiez-le avant de commander.'], $this->etat()['corps']);
        $this->json('GET', '/api/panier');
        self::assertSame(1, $this->etat()['corps']['lignes'][0]['quantite'], 'le panier montre la quantité encore possible');
    }

    public function testPegi18ExigeLaDeclarationDeMajorite(): void
    {
        $offre = $this->creerOffre(pegi18: true);
        $this->stocker($offre, 1);
        $acheteur = $this->acheteurVerifie();
        $jeton = $this->jetonPour($acheteur['email']);
        $this->ajouterAuPanier($offre, 1);

        $this->passer($jeton);
        self::assertSame(422, $this->etat()['statut']);
        self::assertSame(['message' => 'Votre panier contient un jeu PEGI 18 : confirmez que vous êtes majeur pour commander.'], $this->etat()['corps']);

        $this->passer($jeton, majeur: true);
        self::assertSame(201, $this->etat()['statut']);
        self::assertTrue($this->connexion->fetchOne('SELECT confirme_majeur FROM commande WHERE reference = CAST(:r AS uuid)', ['r' => $this->etat()['corps']['reference']]));
    }

    public function testCompteNonVerifie(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 1);
        $acheteur = $this->creerAcheteur();   // e-mail non vérifié
        $this->ajouterAuPanier($offre, 1);

        $this->passer($this->jetonPour($acheteur['email']));

        self::assertSame(403, $this->etat()['statut']);
        self::assertSame(['message' => 'Vérifiez votre adresse e-mail avant de commander.'], $this->etat()['corps']);
    }

    public function testPanierVide(): void
    {
        $this->passer($this->jetonPour($this->acheteurVerifie()['email']));

        self::assertSame(422, $this->etat()['statut']);
        self::assertSame(['message' => 'Votre panier est vide.'], $this->etat()['corps']);
    }

    public function testLaCommandeDAutruiRepondCommeUneCommandeInexistante(): void
    {
        $offre = $this->creerOffre();
        $this->stocker($offre, 1);
        $this->ajouterAuPanier($offre, 1);
        $this->passer($this->jetonPour($this->acheteurVerifie()['email']));
        $reference = $this->etat()['corps']['reference'];
        $intrus = $this->jetonPour($this->acheteurVerifie()['email']);

        foreach ([['GET', ''], ['POST', '/annulation']] as [$methode, $suffixe]) {
            $this->json($methode, '/api/commandes/'.$reference.$suffixe, jeton: $intrus);
            $autrui = $this->etat();
            $this->json($methode, '/api/commandes/0b4e3f7a-1c2d-4e5f-8a9b-0c1d2e3f4a5b'.$suffixe, jeton: $intrus);
            self::assertSame(404, $autrui['statut'], $methode);
            self::assertSame($this->etat(), $autrui, 'aucune différence observable (IDOR)');
        }
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->connexion->fetchOne('SELECT statut FROM commande WHERE reference = CAST(:r AS uuid)', ['r' => $reference]), 'l\'intrus n\'a rien annulé');
        $this->json('GET', '/api/commandes', jeton: $intrus);
        self::assertSame([], $this->etat()['corps']['commandes']);
    }

    public function testBackOfficeEtVisiteurRefuses(): void
    {
        foreach (['gestionnaire@sidekey.test', 'admin@sidekey.test', 'moderateur@sidekey.test'] as $email) {
            $this->passer($this->jetonPour($email));
            self::assertSame(403, $this->etat()['statut'], $email);
            $this->json('GET', '/api/commandes', jeton: $this->jetonPour($email));
            self::assertSame(403, $this->etat()['statut'], $email);
        }
        $this->passer(null);
        self::assertSame(401, $this->etat()['statut']);
    }

    public function testReferenceMalFormee(): void
    {
        $this->json('GET', "/api/commandes/1'%20OR%20'1'='1", jeton: $this->jetonPour($this->acheteurVerifie()['email']));

        self::assertSame(404, $this->etat()['statut']);
    }

    /** @return array{id: string, email: string} */
    private function acheteurVerifie(): array
    {
        $acheteur = $this->creerAcheteur();
        $this->connexion->executeStatement('UPDATE utilisateur SET email_verifie = true WHERE id = CAST(:u AS uuid)', ['u' => $acheteur['id']]);

        return $acheteur;
    }

    private function ajouterAuPanier(string $offreId, int $quantite): void
    {
        $this->json('POST', '/api/panier/lignes', ['offreId' => $offreId, 'quantite' => $quantite]);
        self::assertSame(200, $this->etat()['statut'], 'ajout au panier');
    }

    private function passer(?string $jeton, bool $majeur = false): void
    {
        $this->json('POST', '/api/commandes', ['confirmeMajeur' => $majeur], $jeton);
    }
}
