<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalogue;

use App\Tests\Support\Auth\AuthWebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Base des tests HTTP du back-office catalogue (F-09) : API réelle, PostgreSQL et Redis réels, compte GESTIONNAIRE du jeu
 * d'essai avec A2F validée. Tout ce que crée un test porte le préfixe « zz-test » / « ZZ Test » et est retiré à la fin
 * (offres, jeux, éditeurs, plateformes, fichiers de jaquettes, entrées de cache) ; le jeu d'essai n'est jamais modifié.
 * Le journal d'audit, en ajout seul, garde les traces des actions du compte GESTIONNAIRE (qui n'est jamais supprimé).
 */
abstract class CatalogueAdminWebTestCase extends AuthWebTestCase
{
    public const GESTIONNAIRE = 'gestionnaire@sidekey.test';
    public const DESCRIPTION = 'Une description de test suffisamment longue.';

    private ?string $jeton = null;

    protected function tearDown(): void
    {
        $this->nettoyerCatalogue();
        parent::tearDown();
    }

    protected function nettoyerCatalogue(): void
    {
        $c = $this->connexion;
        $c->executeStatement("DELETE FROM offre WHERE jeu_id IN (SELECT id FROM jeu WHERE slug LIKE 'zz-test%')");
        $c->executeStatement("DELETE FROM jeu_genre WHERE jeu_id IN (SELECT id FROM jeu WHERE slug LIKE 'zz-test%')");
        $c->executeStatement("DELETE FROM jeu_traduction WHERE jeu_id IN (SELECT id FROM jeu WHERE slug LIKE 'zz-test%')");
        $c->executeStatement("DELETE FROM jeu WHERE slug LIKE 'zz-test%'");
        $c->executeStatement("DELETE FROM editeur WHERE nom LIKE 'ZZ Test%'");
        $c->executeStatement("DELETE FROM plateforme WHERE code LIKE 'ZZTEST%'");

        foreach ($this->redis->keys('cache:jeu:zz-test*') ?: [] as $cle) {
            $this->redis->del($cle);
        }
        foreach ($this->fichiersDeJaquettes() as $fichier) {
            @unlink($fichier);
        }
    }

    /** @return list<string> */
    protected function fichiersDeJaquettes(): array
    {
        $dossier = static::getContainer()->getParameter('kernel.cache_dir').'/media/jaquettes';

        return is_dir($dossier) ? array_map(static fn (string $nom): string => $dossier.'/'.$nom, array_values(array_diff(scandir($dossier) ?: [], ['.', '..']))) : [];
    }

    protected function jetonGestionnaire(): string
    {
        return $this->jeton ??= $this->jetonPour(self::GESTIONNAIRE);
    }

    /**
     * @param array<string, mixed>|null $corps
     *
     * @return array{statut: int, corps: array<string, mixed>}
     */
    protected function api(string $methode, string $uri, ?array $corps = null, ?string $jeton = null, string $langue = 'fr'): array
    {
        $this->json($methode, $uri, $corps, $jeton ?? $this->jetonGestionnaire(), ['HTTP_ACCEPT_LANGUAGE' => $langue]);
        $etat = $this->etat();

        return ['statut' => $etat['statut'], 'corps' => $etat['corps']];
    }

    /** @return array{statut: int, corps: array<string, mixed>} */
    protected function televerser(string $jeuId, string $contenu, string $nomClient = 'jaquette.webp', string $typeClient = 'image/webp', string $champ = 'jaquette', ?string $jeton = null): array
    {
        $fichier = tempnam(sys_get_temp_dir(), 'jaq');
        file_put_contents($fichier, $contenu);
        $this->client->request(
            'POST',
            '/api/admin/jeux/'.$jeuId.'/jaquette',
            files: [$champ => new UploadedFile($fichier, $nomClient, $typeClient, null, true)],
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.($jeton ?? $this->jetonGestionnaire()), 'HTTP_ACCEPT_LANGUAGE' => 'fr'],
        );
        @unlink($fichier);
        $etat = $this->etat();

        return ['statut' => $etat['statut'], 'corps' => $etat['corps']];
    }

    protected function creerEditeur(string $nom = 'ZZ Test Éditeur'): string
    {
        $reponse = $this->api('POST', '/api/admin/editeurs', ['nom' => $nom]);
        self::assertSame(201, $reponse['statut']);

        return (string) $reponse['corps']['id'];
    }

    /**
     * @param array<string, mixed> $surcharge
     *
     * @return array<string, mixed> corps de la requête de création d'un jeu
     */
    protected function corpsJeu(string $editeurId, array $surcharge = []): array
    {
        return $surcharge + [
            'titre' => 'ZZ Test Jeu',
            'slug' => 'zz-test-jeu',
            'dateSortie' => '2025-03-14',
            'pegi' => 16,
            'editeurId' => $editeurId,
            'actif' => true,
            'descriptions' => ['fr' => self::DESCRIPTION, 'en' => 'A sufficiently long test description.'],
            'genres' => [],
        ];
    }

    /** @return array<string, mixed> jeu créé (réponse 201) */
    protected function creerJeu(?string $editeurId = null, string $slug = 'zz-test-jeu', string $titre = 'ZZ Test Jeu'): array
    {
        $reponse = $this->api('POST', '/api/admin/jeux', $this->corpsJeu($editeurId ?? $this->creerEditeur('ZZ Test Éditeur '.$slug), ['slug' => $slug, 'titre' => $titre]));
        self::assertSame(201, $reponse['statut'], json_encode($reponse['corps'], \JSON_THROW_ON_ERROR));

        return $reponse['corps'];
    }

    protected function plateformeExistante(): string
    {
        return (string) $this->connexion->fetchOne("SELECT id::text FROM plateforme WHERE code NOT LIKE 'ZZTEST%' ORDER BY code LIMIT 1");
    }
}
