<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Repository\CatalogueRepository;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\JeuResume;
use App\Service\Catalogue\Langue;
use App\Service\Catalogue\TriCatalogue;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Requêtes du catalogue contre la base sidekey_test chargée (make db-seed) : filtres combinables, recherche sans
 * accent ni casse, jokers échappés, tris en liste blanche, pagination, fiche, valeurs des filtres, injections.
 * Jeu d'essai : 40 jeux dont 39 actifs avec offre, 60 offres (STEAM 39, PSN 15, XBOX 5 sur les jeux actifs).
 */
#[Group('database')]
final class CatalogueRepositoryTest extends KernelTestCase
{
    private CatalogueRepository $repository;
    private Connection $connexion;

    protected function setUp(): void
    {
        self::bootKernel();
        $connexion = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $this->connexion = $connexion;
        $this->repository = new CatalogueRepository($connexion);
    }

    // --- Liste, pagination ---------------------------------------------------------------------

    public function testListeParDefautPagineeParVingtEtTrieeParTitre(): void
    {
        $page1 = $this->repository->rechercher(new FiltresCatalogue(), Langue::Fr);
        $page2 = $this->repository->rechercher(new FiltresCatalogue(page: 2), Langue::Fr);

        self::assertSame(39, $page1->total, 'seuls les jeux actifs ayant une offre active');
        self::assertCount(20, $page1->jeux);
        self::assertCount(19, $page2->jeux);
        self::assertSame(2, $page1->nombrePages());
        $slugs = array_map(static fn (JeuResume $j): string => $j->slug, [...$page1->jeux, ...$page2->jeux]);
        self::assertSame($slugs, array_values(array_unique($slugs)), 'aucun doublon entre les pages');
        self::assertNotContains('jeu-essai-40', $slugs, 'un jeu inactif n\'est pas au catalogue');
        self::assertSame(
            (string) $this->connexion->fetchOne('SELECT titre FROM jeu j WHERE actif ORDER BY titre ASC, id ASC LIMIT 1'),
            $page1->jeux[0]->titre,
            'tri par titre : même ordre que la base',
        );
    }

    public function testChaqueCarteContientLesInformationsDeLaMaquette(): void
    {
        $jeu = $this->repository->rechercher(new FiltresCatalogue('Aube'), Langue::Fr)->jeux[0];

        self::assertSame('jeu-essai-01', $jeu->slug);
        self::assertSame("Aube d'Écarlate", $jeu->titre);
        self::assertSame(7, $jeu->pegi);
        self::assertSame('jaquettes/jeu-01.webp', $jeu->jaquette);
        self::assertSame(['PSN', 'STEAM', 'XBOX'], $jeu->plateformes);
        self::assertSame(1499, $jeu->prixMinCentimes, 'prix minimum parmi les offres actives');
        self::assertIsBool($jeu->disponible);
        self::assertSame(3.0, $jeu->noteMoyenne);
        self::assertSame(2, $jeu->nombreAvis);
    }

    public function testPageAuDelaDuDernierResultatEstVideSansErreur(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue(page: 3), Langue::Fr);

        self::assertSame([], $page->jeux);
        self::assertSame(39, $page->total);
    }

    public function testPageExtremeNeLitRienDeCouteux(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue(page: 10_000), Langue::Fr);

        self::assertSame([], $page->jeux);
    }

    public function testLaDisponibiliteCorrespondAuxClesDisponibles(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue(), Langue::Fr);

        foreach ($page->jeux as $jeu) {
            $attendu = (bool) $this->connexion->fetchOne(
                "SELECT EXISTS (SELECT 1 FROM cle_licence k JOIN offre o ON o.id = k.offre_id JOIN jeu j ON j.id = o.jeu_id
                  WHERE j.slug = :s AND o.actif AND k.statut = 'DISPONIBLE')",
                ['s' => $jeu->slug],
            );
            self::assertSame($attendu, $jeu->disponible, $jeu->slug);
        }
    }

    // --- Recherche par titre -------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function rechercheAccentueeOuNon(): iterable
    {
        yield 'sans accent' => ['ecarlate'];
        yield 'avec accent' => ['écarlate'];
        yield 'majuscules accentuées' => ['ÉCARLATE'];
        yield 'majuscules' => ['AUBE D'];
        yield 'milieu de mot' => ['carlat'];
        yield 'avec apostrophe' => ["d'ecarlate"];
    }

    #[DataProvider('rechercheAccentueeOuNon')]
    public function testRechercheInsensibleALaCasseEtAuxAccents(string $terme): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue($terme), Langue::Fr);

        self::assertSame(['jeu-essai-01'], array_map(static fn (JeuResume $j): string => $j->slug, $page->jeux), $terme);
    }

    public function testRechercheAvecAccentDansLeTitreEtSansAccentDansLaSaisie(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue('hotel etoile'), Langue::Fr);

        self::assertSame(['Hôtel Étoilé'], array_map(static fn (JeuResume $j): string => $j->titre, $page->jeux));
    }

    public function testRechercheSansResultat(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue('zzzz introuvable'), Langue::Fr);

        self::assertSame(0, $page->total);
        self::assertSame([], $page->jeux);
    }

    /** @return iterable<string, array{string}> */
    public static function jokers(): iterable
    {
        yield 'pourcentage' => ['%'];
        yield 'souligné' => ['_'];
        yield 'joker au milieu' => ['a%e'];
        yield 'antislash' => ['\\'];
        yield 'souligné au milieu' => ['A_be'];
    }

    #[DataProvider('jokers')]
    public function testLesJokersLikeNeRenvoientPasToutLeCatalogue(string $terme): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue($terme), Langue::Fr);

        self::assertSame(0, $page->total, 'un « '.$terme.' » saisi est un caractère, pas un joker');
    }

    // --- Filtres -------------------------------------------------------------------------------

    public function testFiltreParPlateforme(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue(plateformes: ['XBOX']), Langue::Fr);

        self::assertSame(5, $page->total);
        foreach ($page->jeux as $jeu) {
            self::assertSame(['XBOX'], $jeu->plateformes, 'la carte ne liste que la plateforme filtrée');
        }
    }

    public function testPlusieursPlateformesSontUnOu(): void
    {
        $xbox = $this->repository->rechercher(new FiltresCatalogue(plateformes: ['XBOX']), Langue::Fr)->total;
        $psn = $this->repository->rechercher(new FiltresCatalogue(plateformes: ['PSN']), Langue::Fr)->total;
        $les2 = $this->repository->rechercher(new FiltresCatalogue(plateformes: ['XBOX', 'PSN']), Langue::Fr)->total;

        self::assertSame(15, $psn);
        self::assertGreaterThanOrEqual(max($xbox, $psn), $les2);
        self::assertLessThanOrEqual($xbox + $psn, $les2, 'union, sans doublon');
    }

    public function testFiltreParGenre(): void
    {
        self::assertSame(9, $this->repository->rechercher(new FiltresCatalogue(genres: ['action']), Langue::Fr)->total);
        self::assertSame(0, $this->repository->rechercher(new FiltresCatalogue(genres: ['genre-inexistant']), Langue::Fr)->total);
    }

    public function testFiltrePegiEstUnAgeMaximum(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue(pegi: 7), Langue::Fr);

        self::assertSame(15, $page->total, 'PEGI 3 (7 jeux) et 7 (8 jeux)');
        foreach ($page->jeux as $jeu) {
            self::assertLessThanOrEqual(7, $jeu->pegi);
        }
    }

    public function testFiltreParFourchetteDePrix(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue(prixMinCentimes: 1000, prixMaxCentimes: 2000), Langue::Fr);

        self::assertGreaterThan(0, $page->total);
        foreach ($page->jeux as $jeu) {
            self::assertGreaterThanOrEqual(1000, $jeu->prixMinCentimes);
            self::assertLessThanOrEqual(2000, $jeu->prixMinCentimes);
        }
    }

    public function testLesBornesDePrixSontInclusives(): void
    {
        $exact = $this->repository->rechercher(new FiltresCatalogue(prixMinCentimes: 1499, prixMaxCentimes: 1499), Langue::Fr);

        self::assertContains('jeu-essai-01', array_map(static fn (JeuResume $j): string => $j->slug, $exact->jeux));
    }

    public function testLesFiltresSeCombinent(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue(plateformes: ['PSN'], genres: ['action'], pegi: 16, prixMaxCentimes: 3000), Langue::Fr);

        foreach ($page->jeux as $jeu) {
            self::assertContains('PSN', $jeu->plateformes);
            self::assertLessThanOrEqual(16, $jeu->pegi);
            self::assertLessThanOrEqual(3000, $jeu->prixMinCentimes);
        }
        $tous = $this->repository->rechercher(new FiltresCatalogue(plateformes: ['PSN']), Langue::Fr)->total;
        self::assertLessThanOrEqual($tous, $page->total, 'chaque filtre ajouté restreint le résultat');
    }

    // --- Tris ----------------------------------------------------------------------------------

    public function testTriParPrixCroissantEtDecroissant(): void
    {
        $asc = array_map(static fn (JeuResume $j): int => $j->prixMinCentimes, $this->repository->rechercher(new FiltresCatalogue(tri: TriCatalogue::PrixCroissant), Langue::Fr)->jeux);
        $desc = array_map(static fn (JeuResume $j): int => $j->prixMinCentimes, $this->repository->rechercher(new FiltresCatalogue(tri: TriCatalogue::PrixDecroissant), Langue::Fr)->jeux);

        $a = $asc;
        sort($a);
        $d = $desc;
        rsort($d);
        self::assertSame($a, $asc);
        self::assertSame($d, $desc);
        self::assertSame(999, $asc[0], 'le moins cher en premier (9,99 €)');
    }

    public function testTriParNouveaute(): void
    {
        $page = $this->repository->rechercher(new FiltresCatalogue(tri: TriCatalogue::Nouveaute), Langue::Fr);
        $dates = array_map(fn (JeuResume $j): string => (string) $this->connexion->fetchOne('SELECT date_sortie::text FROM jeu WHERE slug = :s', ['s' => $j->slug]), $page->jeux);

        $tries = $dates;
        rsort($tries);
        self::assertSame($tries, $dates, 'sorties récentes d\'abord');
    }

    public function testTriParNoteMetLesJeuxNotesEnPremier(): void
    {
        $notes = array_map(static fn (JeuResume $j): ?float => $j->noteMoyenne, $this->repository->rechercher(new FiltresCatalogue(tri: TriCatalogue::Note), Langue::Fr)->jeux);

        self::assertNotNull($notes[0]);
        $notesNonNulles = array_values(array_filter($notes, static fn (?float $n): bool => null !== $n));
        $d = $notesNonNulles;
        rsort($d);
        self::assertSame($d, $notesNonNulles, 'notes décroissantes');
        $premierNul = array_search(null, $notes, true);
        if (false !== $premierNul) {
            self::assertSame([], array_filter(\array_slice($notes, 0, $premierNul), static fn (?float $n): bool => null === $n), 'les jeux sans note viennent après');
        }
    }

    public function testLOrdreEstStableEntreDeuxLectures(): void
    {
        foreach (TriCatalogue::cases() as $tri) {
            $a = $this->repository->rechercher(new FiltresCatalogue(tri: $tri, page: 2), Langue::Fr);
            $b = $this->repository->rechercher(new FiltresCatalogue(tri: $tri, page: 2), Langue::Fr);
            self::assertEquals($a, $b, $tri->name);
        }
    }

    // --- Fiche ---------------------------------------------------------------------------------

    public function testFicheCompleteEnFrancais(): void
    {
        $fiche = $this->repository->fiche('jeu-essai-01', Langue::Fr);

        self::assertNotNull($fiche);
        self::assertSame("Aube d'Écarlate", $fiche->titre);
        self::assertSame(7, $fiche->pegi);
        self::assertNotSame('', $fiche->editeur);
        self::assertNotSame('', $fiche->description);
        self::assertNotNull($fiche->dateSortie);
        self::assertCount(3, $fiche->offres);
        self::assertSame(1499, $fiche->offres[0]['prixCentimes'], 'offres triées par prix');
        self::assertSame(['PSN', 'STEAM', 'XBOX'], $this->tries(array_column($fiche->offres, 'plateforme')));
        foreach ($fiche->offres as $offre) {
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $offre['offreId'], 'identifiant d\'offre utilisable par le panier');
            self::assertGreaterThanOrEqual(0, $offre['stock']);
            $attendu = (int) $this->connexion->fetchOne("SELECT count(*) FROM cle_licence WHERE offre_id = :o::uuid AND statut = 'DISPONIBLE'", ['o' => $offre['offreId']]);
            self::assertSame($attendu, $offre['stock'], 'stock = clés DISPONIBLE (RG-02)');
        }
        self::assertNotEmpty($fiche->genres);
        self::assertSame(3.0, $fiche->noteMoyenne);
        self::assertSame(2, $fiche->nombreAvis);
    }

    public function testLaDescriptionEtLesGenresSuiventLaLangue(): void
    {
        $fr = $this->repository->fiche('jeu-essai-01', Langue::Fr);
        $en = $this->repository->fiche('jeu-essai-01', Langue::En);

        self::assertNotNull($fr);
        self::assertNotNull($en);
        self::assertNotSame($fr->description, $en->description);
        self::assertNotSame(array_column($fr->genres, 'libelle'), array_column($en->genres, 'libelle'));
        self::assertSame($this->tries(array_column($fr->genres, 'slug')), $this->tries(array_column($en->genres, 'slug')), 'mêmes genres, libellés traduits');
    }

    public function testRepliSurLAutreLangueQuandLaTraductionManque(): void
    {
        $this->connexion->beginTransaction();
        try {
            $this->connexion->executeStatement("DELETE FROM jeu_traduction WHERE langue_code = 'en' AND jeu_id = (SELECT id FROM jeu WHERE slug = 'jeu-essai-01')");

            $fiche = $this->repository->fiche('jeu-essai-01', Langue::En);

            self::assertNotNull($fiche);
            self::assertNotSame('', $fiche->description, 'la description française remplace la traduction manquante');
        } finally {
            $this->connexion->rollBack();
        }
    }

    public function testFicheInconnueOuInactive(): void
    {
        self::assertNull($this->repository->fiche('inconnu', Langue::Fr));
        self::assertNull($this->repository->fiche('jeu-essai-40', Langue::Fr), 'jeu inactif : retiré du catalogue');
    }

    public function testUneOffreInactiveNApparaitPasSurLaFiche(): void
    {
        $this->connexion->beginTransaction();
        try {
            $this->connexion->executeStatement("UPDATE offre SET actif = false WHERE jeu_id = (SELECT id FROM jeu WHERE slug = 'jeu-essai-01') AND plateforme_id = (SELECT id FROM plateforme WHERE code = 'XBOX')");

            $fiche = $this->repository->fiche('jeu-essai-01', Langue::Fr);

            self::assertNotNull($fiche);
            self::assertCount(2, $fiche->offres);
        } finally {
            $this->connexion->rollBack();
        }
    }

    public function testLaFicheNExposeAucuneCleDeLicence(): void
    {
        $fiche = $this->repository->fiche('jeu-essai-01', Langue::Fr);

        $json = json_encode($fiche?->enTableau(), \JSON_THROW_ON_ERROR);
        foreach (['cle_chiffree', 'empreinte', 'cleChiffree', 'statut'] as $interdit) {
            self::assertStringNotContainsString($interdit, $json);
        }
    }

    // --- Valeurs des filtres -------------------------------------------------------------------

    public function testValeursDesFiltres(): void
    {
        $valeurs = $this->repository->valeursFiltres(Langue::Fr);

        self::assertSame(['PSN', 'STEAM', 'XBOX'], $this->tries(array_column($valeurs->plateformes, 'code')));
        self::assertCount(8, $valeurs->genres);
        self::assertSame(999, $valeurs->prixMinCentimes);
        self::assertSame(5499, $valeurs->prixMaxCentimes);
        $en = $this->repository->valeursFiltres(Langue::En);
        self::assertNotSame(array_column($valeurs->genres, 'libelle'), array_column($en->genres, 'libelle'), 'genres traduits');
    }

    // --- Injections ----------------------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function injections(): iterable
    {
        yield 'quote' => ["'"];
        yield 'OR 1=1' => ["' OR '1'='1"];
        yield 'union' => ["' UNION SELECT mot_de_passe_hash FROM utilisateur --"];
        yield 'drop' => ["'; DROP TABLE jeu; --"];
        yield 'sleep' => ["' || pg_sleep(5) || '"];
        yield 'commentaire' => ['/*'];
        yield 'dollar' => ['$$ x $$'];
    }

    #[DataProvider('injections')]
    public function testInjectionSqlDansLaRecherche(string $terme): void
    {
        $avant = (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu');

        $debut = microtime(true);
        $page = $this->repository->rechercher(new FiltresCatalogue($terme), Langue::Fr);

        // Une apostrophe seule est une recherche légitime : elle trouve les titres qui en contiennent (d'Écarlate…).
        $attendu = "'" === $terme ? (int) $this->connexion->fetchOne("SELECT count(*) FROM jeu WHERE actif AND titre LIKE '%''%'") : 0;
        self::assertSame($attendu, $page->total);
        self::assertLessThan(3.0, microtime(true) - $debut);
        self::assertSame($avant, (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu'));
    }

    public function testInjectionDansLaFicheNeRetourneRien(): void
    {
        // Le service refuse déjà ces slugs ; le dépôt, lui, ne doit pas pour autant exécuter la valeur.
        foreach (["jeu-essai-01' OR '1'='1", "x'; DROP TABLE jeu; --"] as $slug) {
            self::assertNull($this->repository->fiche($slug, Langue::Fr));
        }
        self::assertSame(40, (int) $this->connexion->fetchOne('SELECT count(*) FROM jeu'));
    }

    /**
     * @param list<string> $valeurs
     *
     * @return list<string>
     */
    private function tries(array $valeurs): array
    {
        sort($valeurs);

        return $valeurs;
    }
}
