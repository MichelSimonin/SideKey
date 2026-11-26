<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Tests\Support\Cles\ClesWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/** US-52 de bout en bout : HTTP multipart, RBAC, PostgreSQL (contrainte UNIQUE, compteurs), journal. */
#[Group('database')]
#[Group('redis')]
final class ImportClesControllerTest extends ClesWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.80';
    }

    public function testLeGestionnaireImporteUnLotEtObtientLeRapport(): void
    {
        $offre = $this->creerOffre();
        [$a, $b] = [self::nouvelleCle(), self::nouvelleCle()];

        $this->importer($offre, "cle\n$a\n".strtolower($a)."\nmauvaise cle\n$b\n", $this->jetonPour(self::GESTIONNAIRE));

        $etat = $this->etat();
        self::assertSame(201, $etat['statut']);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('TERMINE', $etat['corps']['statut']);
        self::assertSame('cles-test-lot.csv', $etat['corps']['fichier']);
        self::assertSame([4, 2, 1, 1], [$etat['corps']['lignes'], $etat['corps']['acceptees'], $etat['corps']['doublons'], $etat['corps']['erreurs']]);
        self::assertSame([['ligne' => 3, 'motif' => 'doublon_fichier'], ['ligne' => 4, 'motif' => 'format']], $etat['corps']['refus']);

        $import = $this->connexion->fetchAssociative('SELECT nb_lignes, nb_acceptees, nb_doublons, nb_erreurs, statut FROM import_cles WHERE id = CAST(:id AS uuid)', ['id' => $etat['corps']['id']]);
        self::assertSame(['nb_lignes' => 4, 'nb_acceptees' => 2, 'nb_doublons' => 1, 'nb_erreurs' => 1, 'statut' => 'TERMINE'], $import);
        $cles = $this->connexion->fetchAllAssociative("SELECT statut, suffixe, octet_length(empreinte) AS e FROM cle_licence WHERE offre_id = CAST(:o AS uuid) ORDER BY suffixe", ['o' => $offre]);
        self::assertCount(2, $cles);
        foreach ($cles as $cle) {
            self::assertSame('DISPONIBLE', $cle['statut']);
            self::assertSame(32, $cle['e']);
        }
        $suffixes = array_map(static fn (string $c): string => substr(str_replace('-', '', $c), -4), [$a, $b]);
        sort($suffixes);
        self::assertSame($suffixes, array_column($cles, 'suffixe'));

        $journal = $this->connexion->fetchOne("SELECT count(*) FROM journal_audit WHERE action = 'IMPORT_CLES' AND ressource_id = CAST(:id AS uuid)", ['id' => $etat['corps']['id']]);
        self::assertSame(1, $journal);
    }

    public function testReimporterLesMemesClesTouteEstDoublon(): void
    {
        $offre = $this->creerOffre();
        $cles = self::nouvelleCle()."\n".self::nouvelleCle()."\n";
        $this->importer($offre, $cles, $this->jetonPour(self::GESTIONNAIRE));

        $this->importer($offre, $cles, $this->jetonPour(self::GESTIONNAIRE));

        $corps = $this->etat()['corps'];
        self::assertSame([0, 2], [$corps['acceptees'], $corps['doublons']]);
        self::assertSame([['ligne' => 1, 'motif' => 'doublon_stock'], ['ligne' => 2, 'motif' => 'doublon_stock']], $corps['refus']);
    }

    public function testUneCleDejaVendueSurUneAutreOffreEstAussiUnDoublon(): void
    {
        $valeur = self::nouvelleCle();
        $this->commandeAvecCle($this->creerAcheteur()['id'], 'PAYEE', $valeur);

        $this->importer($this->creerOffre(), $valeur."\n", $this->jetonPour(self::GESTIONNAIRE));

        self::assertSame([['ligne' => 1, 'motif' => 'doublon_stock']], $this->etat()['corps']['refus']);
    }

    public function testAdministrateurAutorise(): void
    {
        $this->importer($this->creerOffre(), self::nouvelleCle()."\n", $this->jetonPour('admin@sidekey.test'));

        self::assertSame(201, $this->etat()['statut']);
    }

    public function testClientEtModerateurRefuses(): void
    {
        $offre = $this->creerOffre();
        foreach (['client01@sidekey.test', 'moderateur@sidekey.test'] as $email) {
            $this->importer($offre, self::nouvelleCle()."\n", $this->jetonPour($email));
            self::assertSame(403, $this->etat()['statut'], $email);
        }
        self::assertSame(0, $this->connexion->fetchOne('SELECT count(*) FROM cle_licence WHERE offre_id = CAST(:o AS uuid)', ['o' => $offre]));
    }

    public function testVisiteurNonAuthentifie(): void
    {
        $this->importer($this->creerOffre(), self::nouvelleCle()."\n", null);

        self::assertSame(401, $this->etat()['statut']);
    }

    public function testOffreInconnueOuRetireeDeLaVente(): void
    {
        $this->importer('9f8e7d6c-5b4a-4392-8170-6f5e4d3c2b1a', self::nouvelleCle()."\n", $this->jetonPour(self::GESTIONNAIRE));
        self::assertSame(404, $this->etat()['statut']);

        $this->importer($this->creerOffre(active: false), self::nouvelleCle()."\n", $this->jetonPour(self::GESTIONNAIRE));
        self::assertSame(404, $this->etat()['statut']);
    }

    public function testFichierAbsent(): void
    {
        $this->client->request('POST', '/api/admin/offres/'.$this->creerOffre().'/cles', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jetonPour(self::GESTIONNAIRE)]);

        self::assertSame(422, $this->etat()['statut']);
        self::assertSame(['message' => 'Joignez un fichier CSV de clés (champ « fichier »).'], $this->etat()['corps']);
    }

    public function testMauvaiseExtension(): void
    {
        $this->importer($this->creerOffre(), self::nouvelleCle()."\n", $this->jetonPour(self::GESTIONNAIRE), 'cles-test.xlsx');

        self::assertSame(422, $this->etat()['statut']);
    }

    public function testFichierTropGros(): void
    {
        $this->importer($this->creerOffre(), str_repeat(self::nouvelleCle()."\n", 25000), $this->jetonPour(self::GESTIONNAIRE));

        self::assertSame(413, $this->etat()['statut']);
    }

    public function testPlusDe1000ClesMessageEnAnglais(): void
    {
        $lignes = implode("\n", array_map(static fn (): string => self::nouvelleCle(), range(1, 1001)));
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en');

        $this->importer($this->creerOffre(), $lignes, $this->jetonPour(self::GESTIONNAIRE));

        self::assertSame(422, $this->etat()['statut']);
        self::assertSame(['message' => 'The file contains more than 1000 keys: split it into several imports.'], $this->etat()['corps']);
    }

    public function testIdentifiantDOffreMalForme(): void
    {
        $this->client->request('POST', '/api/admin/offres/1%20OR%201=1/cles', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->jetonPour(self::GESTIONNAIRE)]);

        self::assertSame(404, $this->etat()['statut']);
    }
}
