<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\Catalogue\CatalogueAdminWebTestCase;
use App\Tests\Support\Catalogue\FabriqueWebp;
use PHPUnit\Framework\Attributes\Group;

/**
 * F-09 de bout en bout (US-50, US-51) : API réelle, PostgreSQL et Redis réels, compte GESTIONNAIRE avec A2F.
 * Critères du ticket : CRUD jeux, éditeurs et plateformes ; validation côté API ; upload sécurisé ; accès restreint
 * (matrice de rôles dans Security/CatalogueAdminSecuriteTest).
 */
#[Group('database')]
#[Group('redis')]
final class CatalogueAdminApiTest extends CatalogueAdminWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.90';
    }

    // ---- Éditeurs ----------------------------------------------------------------------------------------------

    public function testCrudEditeurs(): void
    {
        $cree = $this->api('POST', '/api/admin/editeurs', ['nom' => 'ZZ Test Éditeur']);
        self::assertSame(201, $cree['statut']);
        self::assertSame(['id', 'nom', 'nombreJeux'], array_keys($cree['corps']));
        $id = (string) $cree['corps']['id'];

        $liste = $this->api('GET', '/api/admin/editeurs');
        self::assertSame(200, $liste['statut']);
        self::assertContains($id, array_column((array) $liste['corps']['editeurs'], 'id'));

        $modifie = $this->api('PUT', '/api/admin/editeurs/'.$id, ['nom' => 'ZZ Test Éditeur Renommé']);
        self::assertSame(200, $modifie['statut']);
        self::assertSame('ZZ Test Éditeur Renommé', $modifie['corps']['nom']);

        self::assertSame(204, $this->api('DELETE', '/api/admin/editeurs/'.$id)['statut']);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM editeur WHERE nom LIKE 'ZZ Test%'"));
    }

    public function testValidationEtConflitsDesEditeurs(): void
    {
        $vide = $this->api('POST', '/api/admin/editeurs', ['nom' => '']);
        self::assertSame(422, $vide['statut']);
        self::assertArrayHasKey('nom', (array) $vide['corps']['erreurs']);

        $trop = $this->api('POST', '/api/admin/editeurs', ['nom' => str_repeat('a', 121)]);
        self::assertSame(422, $trop['statut']);

        $balise = $this->api('POST', '/api/admin/editeurs', ['nom' => 'ZZ Test <script>alert(1)</script>']);
        self::assertSame(422, $balise['statut']);
        self::assertArrayHasKey('nom', (array) $balise['corps']['erreurs'], 'erreur par champ, comme une validation de DTO');

        $id = $this->creerEditeur();
        $doublon = $this->api('POST', '/api/admin/editeurs', ['nom' => 'ZZ Test Éditeur']);
        self::assertSame(409, $doublon['statut']);
        self::assertSame('Un éditeur porte déjà ce nom.', $doublon['corps']['message']);

        $this->creerJeu($id);
        $utilise = $this->api('DELETE', '/api/admin/editeurs/'.$id);
        self::assertSame(409, $utilise['statut']);
        self::assertStringContainsString('encore utilisé', (string) $utilise['corps']['message']);

        self::assertSame(404, $this->api('PUT', '/api/admin/editeurs/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', ['nom' => 'ZZ Test X'])['statut']);
        self::assertSame(404, $this->api('DELETE', '/api/admin/editeurs/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')['statut']);
        self::assertSame(404, $this->api('DELETE', '/api/admin/editeurs/pas-un-uuid')['statut'], 'la route elle-même n\'accepte que des UUID');
    }

    // ---- Plateformes -------------------------------------------------------------------------------------------

    public function testCrudPlateformesLeCodeEstImmuable(): void
    {
        $cree = $this->api('POST', '/api/admin/plateformes', ['code' => 'ZZTEST', 'libelle' => 'ZZ Test']);
        self::assertSame(201, $cree['statut']);
        self::assertSame(['id', 'code', 'libelle', 'nombreOffres'], array_keys($cree['corps']));
        $id = (string) $cree['corps']['id'];

        $modifie = $this->api('PUT', '/api/admin/plateformes/'.$id, ['libelle' => 'ZZ Test Renommée', 'code' => 'AUTRE']);
        self::assertSame(200, $modifie['statut']);
        self::assertSame('ZZ Test Renommée', $modifie['corps']['libelle']);
        self::assertSame('ZZTEST', $modifie['corps']['code'], 'le code envoyé est ignoré : il est immuable');

        self::assertSame(409, $this->api('POST', '/api/admin/plateformes', ['code' => 'ZZTEST', 'libelle' => 'Doublon'])['statut']);
        foreach (['zztest', 'ZZ-TEST', 'ZZTEST_TROP_LONG', ''] as $code) {
            $refus = $this->api('POST', '/api/admin/plateformes', ['code' => $code, 'libelle' => 'Test']);
            self::assertSame(422, $refus['statut'], 'code « '.$code.' »');
            self::assertArrayHasKey('code', (array) $refus['corps']['erreurs']);
        }

        self::assertSame(204, $this->api('DELETE', '/api/admin/plateformes/'.$id)['statut']);
        self::assertSame(404, $this->api('DELETE', '/api/admin/plateformes/'.$id)['statut']);
    }

    public function testUnePlateformeAvecOffresNeSeSupprimePas(): void
    {
        $plateforme = (string) $this->api('POST', '/api/admin/plateformes', ['code' => 'ZZTEST', 'libelle' => 'ZZ Test'])['corps']['id'];
        $jeu = $this->creerJeu();
        self::assertSame(201, $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => $plateforme, 'prix' => '9.99', 'seuilAlerte' => 2])['statut']);

        $refus = $this->api('DELETE', '/api/admin/plateformes/'.$plateforme);
        self::assertSame(409, $refus['statut']);
        self::assertStringNotContainsString('SQLSTATE', (string) $this->client->getResponse()->getContent());
    }

    // ---- Jeux --------------------------------------------------------------------------------------------------

    public function testCrudJeuxAvecTraductionsGenresEtSuppressionLogique(): void
    {
        $genres = array_map('strval', $this->connexion->fetchFirstColumn('SELECT slug FROM genre ORDER BY slug LIMIT 2'));
        $editeur = $this->creerEditeur();

        $cree = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['genres' => $genres]));
        self::assertSame(201, $cree['statut']);
        $jeu = $cree['corps'];
        self::assertSame('zz-test-jeu', $jeu['slug']);
        self::assertSame($genres, $jeu['genres']);
        self::assertSame(self::DESCRIPTION, $jeu['descriptions']['fr']);
        self::assertSame('A sufficiently long test description.', $jeu['descriptions']['en']);
        self::assertSame(['id' => $editeur, 'nom' => 'ZZ Test Éditeur'], $jeu['editeur']);
        self::assertNull($jeu['jaquette']);
        self::assertSame([], $jeu['offres']);

        $lu = $this->api('GET', '/api/admin/jeux/'.$jeu['id']);
        self::assertSame($jeu, $lu['corps']);

        $modifie = $this->api('PUT', '/api/admin/jeux/'.$jeu['id'], $this->corpsJeu($editeur, ['titre' => 'ZZ Test Jeu Modifié', 'slug' => null, 'pegi' => 18, 'genres' => [$genres[0]]]));
        self::assertSame(200, $modifie['statut']);
        self::assertSame('ZZ Test Jeu Modifié', $modifie['corps']['titre']);
        self::assertSame('zz-test-jeu', $modifie['corps']['slug'], 'sans slug dans le corps, l\'URL existante est conservée');
        self::assertSame(18, $modifie['corps']['pegi']);
        self::assertSame([$genres[0]], $modifie['corps']['genres']);

        self::assertSame(204, $this->api('DELETE', '/api/admin/jeux/'.$jeu['id'])['statut']);
        $apres = $this->api('GET', '/api/admin/jeux/'.$jeu['id']);
        self::assertSame(200, $apres['statut'], 'suppression logique : toujours visible du back-office');
        self::assertFalse($apres['corps']['actif']);
    }

    public function testLeSlugEstDeriveDuTitreEtNumeroteSiPris(): void
    {
        $editeur = $this->creerEditeur();
        $premier = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['titre' => 'ZZ Test Étoile Noire', 'slug' => null]));
        $second = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['titre' => 'ZZ Test Étoile Noire', 'slug' => null]));

        self::assertSame(201, $second['statut']);
        self::assertSame('zz-test-etoile-noire', $premier['corps']['slug']);
        self::assertSame('zz-test-etoile-noire-2', $second['corps']['slug']);
    }

    public function testUnSlugSaisiDejaPrisEstRefuseEn409(): void
    {
        $this->creerJeu();
        $refus = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($this->creerEditeur('ZZ Test Autre'), ['titre' => 'ZZ Test Autre jeu']));

        self::assertSame(409, $refus['statut']);
        self::assertSame('Ce slug est déjà utilisé par un autre jeu.', $refus['corps']['message']);
    }

    public function testValidationDuFormulaireDeJeu(): void
    {
        $editeur = $this->creerEditeur();
        $cas = [
            'titre' => ['titre' => ''],
            'pegi' => ['pegi' => 10],
            'editeurId' => ['editeurId' => 'pas-un-uuid'],
            'descriptions.fr' => ['descriptions' => ['fr' => 'courte', 'en' => self::DESCRIPTION]],
            'descriptions.en' => ['descriptions' => ['fr' => self::DESCRIPTION, 'en' => '']],
            'dateSortie' => ['dateSortie' => '14/03/2025'],
            'genres' => ['genres' => array_map('strval', range(1, 11))],
        ];
        foreach ($cas as $champ => $surcharge) {
            $reponse = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, $surcharge));
            self::assertSame(422, $reponse['statut'], $champ);
            self::assertArrayHasKey($champ, (array) $reponse['corps']['erreurs'], $champ);
        }
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu WHERE slug LIKE 'zz-test%'"), 'aucun jeu créé par un formulaire invalide');

        // Valeurs acceptées par le format mais refusées par le métier : un seul 422 par champ.
        $inconnu = $this->api('POST', '/api/admin/jeux', $this->corpsJeu('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'));
        self::assertSame(422, $inconnu['statut']);
        self::assertArrayHasKey('editeurId', (array) $inconnu['corps']['erreurs']);
        $genre = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['genres' => ['genre-inconnu']]));
        self::assertSame(422, $genre['statut']);
        self::assertArrayHasKey('genres', (array) $genre['corps']['erreurs']);
        self::assertSame(0, (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu WHERE slug LIKE 'zz-test%'"));
    }

    public function testLesErreursSontTraduites(): void
    {
        $fr = $this->api('POST', '/api/admin/jeux', ['titre' => ''], langue: 'fr');
        $en = $this->api('POST', '/api/admin/editeurs', ['nom' => 'ZZ Test <b>'], langue: 'en');

        self::assertSame('Certaines données sont invalides.', $fr['corps']['message']);
        self::assertSame('Some data is invalid.', $en['corps']['message']);
        self::assertSame('Invalid value.', $en['corps']['erreurs']['nom']);

        $this->creerEditeur();
        $doublon = $this->api('POST', '/api/admin/editeurs', ['nom' => 'ZZ Test Éditeur'], langue: 'en');
        self::assertSame('A publisher already has this name.', $doublon['corps']['message']);
    }

    public function testListeDesJeuxFiltreEtPagine(): void
    {
        $editeur = $this->creerEditeur();
        $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['titre' => 'ZZ Test Étoile', 'slug' => 'zz-test-etoile']));
        $inactif = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeur, ['titre' => 'ZZ Test Lune', 'slug' => 'zz-test-lune', 'actif' => false]));
        self::assertFalse($inactif['corps']['actif']);

        $tous = $this->api('GET', '/api/admin/jeux?q=zz%20test');
        self::assertSame(200, $tous['statut']);
        self::assertSame(2, $tous['corps']['total']);
        self::assertSame(['id', 'slug', 'titre', 'editeur', 'pegi', 'actif', 'jaquette', 'nombreOffres'], array_keys($tous['corps']['jeux'][0]));

        self::assertSame(['zz-test-lune'], array_column((array) $this->api('GET', '/api/admin/jeux?q=zz%20test&statut=inactifs')['corps']['jeux'], 'slug'));
        self::assertSame(['zz-test-etoile'], array_column((array) $this->api('GET', '/api/admin/jeux?q=ZZ%20TEST%20ETOILE&statut=actifs')['corps']['jeux'], 'slug'), 'sans accent ni casse');

        $page1 = $this->api('GET', '/api/admin/jeux')['corps'];
        self::assertCount(20, $page1['jeux']);
        self::assertSame(1, $page1['page']);
        self::assertGreaterThanOrEqual(3, $page1['nombrePages']);

        foreach (['statut=tout', 'page=0', 'page=abc', 'q='.str_repeat('a', 101)] as $requete) {
            self::assertSame(422, $this->api('GET', '/api/admin/jeux?'.$requete)['statut'], $requete);
        }
    }

    public function testLesGenresSontProposes(): void
    {
        $reponse = $this->api('GET', '/api/admin/genres');

        self::assertSame(200, $reponse['statut']);
        self::assertNotEmpty($reponse['corps']['genres']);
        self::assertSame(['slug', 'libelle'], array_keys($reponse['corps']['genres'][0]));
    }

    // ---- Offres ------------------------------------------------------------------------------------------------

    public function testCreationEtModificationDUneOffreAvecVerrouillageOptimiste(): void
    {
        $jeu = $this->creerJeu();
        $plateforme = $this->plateformeExistante();

        $creee = $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => $plateforme, 'prix' => '14.99', 'seuilAlerte' => 3]);
        self::assertSame(201, $creee['statut']);
        $offre = $creee['corps'];
        self::assertSame('14.99', $offre['prix']);
        self::assertSame(1, $offre['version']);
        self::assertSame(0, $offre['stock']);
        self::assertSame($plateforme, $offre['plateforme']['id']);

        // Deux gestionnaires ont chargé la version 1 : le premier enregistre, le second est refusé sans rien écraser.
        $premier = $this->api('PUT', '/api/admin/offres/'.$offre['id'], ['prix' => '12.50', 'seuilAlerte' => 3, 'actif' => true, 'version' => 1]);
        self::assertSame(200, $premier['statut']);
        self::assertSame(2, $premier['corps']['version']);

        $second = $this->api('PUT', '/api/admin/offres/'.$offre['id'], ['prix' => '1.00', 'seuilAlerte' => 99, 'actif' => false, 'version' => 1]);
        self::assertSame(409, $second['statut']);
        self::assertSame('Offre modifiée par un collègue : rechargez la page avant de réessayer.', $second['corps']['message']);
        self::assertSame(2, $second['corps']['versionActuelle']);

        $apres = $this->api('GET', '/api/admin/jeux/'.$jeu['id'])['corps']['offres'][0];
        self::assertSame('12.50', $apres['prix'], 'le travail du premier gestionnaire est intact');
        self::assertTrue($apres['actif']);

        $english = $this->api('PUT', '/api/admin/offres/'.$offre['id'], ['prix' => '1.00', 'seuilAlerte' => 1, 'actif' => true, 'version' => 1], langue: 'en');
        self::assertSame('Offer modified by a colleague: reload the page before trying again.', $english['corps']['message']);

        // Après rechargement : version courante, la modification passe.
        self::assertSame(200, $this->api('PUT', '/api/admin/offres/'.$offre['id'], ['prix' => '9.99', 'seuilAlerte' => 1, 'actif' => false, 'version' => 2])['statut']);
    }

    public function testValidationDesOffres(): void
    {
        $jeu = $this->creerJeu();
        $plateforme = $this->plateformeExistante();
        $offre = $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => $plateforme, 'prix' => '10.00', 'seuilAlerte' => 1])['corps'];

        foreach (['0', '0.00', '-5', '14.999', '1e2', 'gratuit', '14,99', '999999', ''] as $prix) {
            $creation = $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => $this->plateformeExistante(), 'prix' => $prix, 'seuilAlerte' => 1]);
            self::assertSame(422, $creation['statut'], 'création, prix « '.$prix.' »');
            self::assertArrayHasKey('prix', (array) $creation['corps']['erreurs']);

            $modif = $this->api('PUT', '/api/admin/offres/'.$offre['id'], ['prix' => $prix, 'seuilAlerte' => 1, 'actif' => true, 'version' => 1]);
            self::assertSame(422, $modif['statut'], 'modification, prix « '.$prix.' »');
        }
        foreach (['seuilAlerte' => -1, 'version' => 0] as $champ => $valeur) {
            $corps = ['prix' => '10.00', 'seuilAlerte' => 1, 'actif' => true, 'version' => 1, $champ => $valeur];
            self::assertSame(422, $this->api('PUT', '/api/admin/offres/'.$offre['id'], $corps)['statut'], $champ);
        }
        // Sans « version » : pas de verrouillage possible, la requête est refusée plutôt qu'acceptée en écrasant.
        $sansVersion = $this->api('PUT', '/api/admin/offres/'.$offre['id'], ['prix' => '10.00', 'seuilAlerte' => 1, 'actif' => true]);
        self::assertSame(422, $sansVersion['statut']);
        self::assertArrayHasKey('version', (array) $sansVersion['corps']['erreurs']);

        self::assertSame('10.00', $this->api('GET', '/api/admin/jeux/'.$jeu['id'])['corps']['offres'][0]['prix'], 'aucune modification invalide n\'a été écrite');
    }

    public function testUneSecondeOffreActiveSurLaMemePlateformeEstRefusee(): void
    {
        $jeu = $this->creerJeu();
        $plateforme = $this->plateformeExistante();
        $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => $plateforme, 'prix' => '10.00', 'seuilAlerte' => 1]);

        $doublon = $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => $plateforme, 'prix' => '12.00', 'seuilAlerte' => 1]);
        self::assertSame(409, $doublon['statut']);

        self::assertSame(404, $this->api('POST', '/api/admin/jeux/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/offres', ['plateformeId' => $plateforme, 'prix' => '1.00', 'seuilAlerte' => 1])['statut']);
        self::assertSame(404, $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'prix' => '1.00', 'seuilAlerte' => 1])['statut']);
        self::assertSame(404, $this->api('PUT', '/api/admin/offres/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', ['prix' => '1.00', 'seuilAlerte' => 1, 'actif' => true, 'version' => 1])['statut']);
    }

    // ---- Jaquettes ---------------------------------------------------------------------------------------------

    public function testTeleverserUneJaquetteLaRemplacerPuisLaRetirer(): void
    {
        $jeu = $this->creerJeu();
        $image = FabriqueWebp::creer(300, 400);

        $premier = $this->televerser($jeu['id'], $image, nomClient: 'ma jaquette.webp');
        self::assertSame(200, $premier['statut']);
        $chemin = (string) $premier['corps']['jaquette'];
        self::assertMatchesRegularExpression('/^jaquettes\/[0-9a-f]{32}\.webp$/', $chemin, 'nom régénéré, jamais celui du client');
        self::assertCount(1, $this->fichiersDeJaquettes());
        self::assertSame($image, file_get_contents($this->racineMedia().'/'.$chemin));

        $deuxieme = $this->televerser($jeu['id'], FabriqueWebp::creer(500, 500));
        self::assertSame(200, $deuxieme['statut']);
        self::assertNotSame($chemin, $deuxieme['corps']['jaquette']);
        self::assertCount(1, $this->fichiersDeJaquettes(), 'l\'ancien fichier est supprimé : pas d\'orphelin');
        self::assertFileDoesNotExist($this->racineMedia().'/'.$chemin);

        self::assertSame(204, $this->api('DELETE', '/api/admin/jeux/'.$jeu['id'].'/jaquette')['statut']);
        self::assertSame([], $this->fichiersDeJaquettes());
        self::assertNull($this->api('GET', '/api/admin/jeux/'.$jeu['id'])['corps']['jaquette']);
    }

    public function testUneJaquetteSurUnJeuInconnuNeLaisseAucunFichier(): void
    {
        $reponse = $this->televerser('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', FabriqueWebp::creer());

        self::assertSame(404, $reponse['statut']);
        self::assertSame([], $this->fichiersDeJaquettes());
    }

    // ---- Cache du catalogue public (F-01) ----------------------------------------------------------------------

    public function testChaqueEcritureInvalideLeCacheDuCataloguePublic(): void
    {
        $jeu = $this->creerJeu();
        $plateforme = $this->plateformeExistante();
        $offre = $this->api('POST', '/api/admin/jeux/'.$jeu['id'].'/offres', ['plateformeId' => $plateforme, 'prix' => '10.00', 'seuilAlerte' => 1])['corps'];

        // Fiche publique, mise en cache par le premier appel.
        $this->client->request('GET', '/api/jeux/zz-test-jeu', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame(1, $this->redis->exists('cache:jeu:zz-test-jeu:fr'), 'la fiche est en cache');

        // Modifier le jeu : le titre change immédiatement pour les visiteurs, sans attendre la fin du TTL de 5 minutes.
        $this->api('PUT', '/api/admin/jeux/'.$jeu['id'], $this->corpsJeu($jeu['editeur']['id'], ['titre' => 'ZZ Test Nouveau Titre']));
        self::assertSame(0, $this->redis->exists('cache:jeu:zz-test-jeu:fr'), 'fiche retirée du cache par la modification');
        $this->client->request('GET', '/api/jeux/zz-test-jeu', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSame('ZZ Test Nouveau Titre', json_decode((string) $this->client->getResponse()->getContent(), true)['titre']);

        // Modifier le prix de l'offre.
        self::assertSame(1, $this->redis->exists('cache:jeu:zz-test-jeu:fr'));
        $this->api('PUT', '/api/admin/offres/'.$offre['id'], ['prix' => '7.77', 'seuilAlerte' => 1, 'actif' => true, 'version' => 1]);
        self::assertSame(0, $this->redis->exists('cache:jeu:zz-test-jeu:fr'), 'fiche retirée du cache par le changement de prix');
        $this->client->request('GET', '/api/jeux/zz-test-jeu', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertStringContainsString('7.77', (string) $this->client->getResponse()->getContent());

        // Renommer l'éditeur invalide la fiche des jeux qui en dépendent.
        self::assertSame(1, $this->redis->exists('cache:jeu:zz-test-jeu:fr'));
        $this->api('PUT', '/api/admin/editeurs/'.$jeu['editeur']['id'], ['nom' => 'ZZ Test Éditeur Renommé']);
        self::assertSame(0, $this->redis->exists('cache:jeu:zz-test-jeu:fr'));
        $this->client->request('GET', '/api/jeux/zz-test-jeu', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSame('ZZ Test Éditeur Renommé', json_decode((string) $this->client->getResponse()->getContent(), true)['editeur']);

        // Désactiver le jeu le retire du catalogue public immédiatement (et le 404 n'est jamais mis en cache).
        $this->api('DELETE', '/api/admin/jeux/'.$jeu['id']);
        $this->client->request('GET', '/api/jeux/zz-test-jeu', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr']);
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        self::assertSame(0, $this->redis->exists('cache:jeu:zz-test-jeu:fr'));
    }

    public function testLesListesPubliquesSontInvalideesParLaVersion(): void
    {
        $avant = (int) $this->redis->get('cache:jeux:version');
        $this->creerJeu();

        self::assertGreaterThan($avant, (int) $this->redis->get('cache:jeux:version'), 'compteur de version incrémenté : toutes les listes en cache deviennent obsolètes');
    }

    // ---- Journal d'audit ---------------------------------------------------------------------------------------

    public function testLesEcrituresSontTraceesSansDonneeSensible(): void
    {
        $avant = (int) $this->connexion->fetchOne("SELECT count(*) FROM journal_audit WHERE action LIKE 'CATALOGUE_%'");
        $jeu = $this->creerJeu();   // éditeur + jeu = 2 traces
        $this->api('PUT', '/api/admin/jeux/'.$jeu['id'], $this->corpsJeu($jeu['editeur']['id'], ['titre' => 'ZZ Test Autre titre']));
        $this->api('DELETE', '/api/admin/jeux/'.$jeu['id']);

        $traces = $this->connexion->fetchAllAssociative(
            "SELECT action, ressource, ressource_id::text AS ressource_id, utilisateur_id::text AS utilisateur_id FROM journal_audit WHERE action LIKE 'CATALOGUE_%' ORDER BY cree_le DESC, id DESC LIMIT 3",
        );
        self::assertSame($avant + 4, (int) $this->connexion->fetchOne("SELECT count(*) FROM journal_audit WHERE action LIKE 'CATALOGUE_%'"));
        self::assertSame(['CATALOGUE_SUPPRESSION', 'CATALOGUE_MODIFICATION', 'CATALOGUE_CREATION'], array_column($traces, 'action'));
        self::assertSame(['jeu', 'jeu', 'jeu'], array_column($traces, 'ressource'));
        self::assertSame($jeu['id'], $traces[0]['ressource_id']);
        self::assertSame((string) $this->connexion->fetchOne("SELECT id::text FROM utilisateur WHERE email = 'gestionnaire@sidekey.test'"), $traces[0]['utilisateur_id']);
    }

    // ---- En-têtes ----------------------------------------------------------------------------------------------

    public function testLesReponsesDuBackOfficeNeSontJamaisMisesEnCache(): void
    {
        foreach (['/api/admin/editeurs', '/api/admin/plateformes', '/api/admin/jeux', '/api/admin/genres'] as $uri) {
            $this->api('GET', $uri);
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'), $uri);
        }
    }

    private function racineMedia(): string
    {
        return static::getContainer()->getParameter('kernel.cache_dir').'/media';
    }
}
