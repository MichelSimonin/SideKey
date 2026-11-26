<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\Cles\ClesWebTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Critère de sécurité de F-08 (CDC §14.1, ADR-0004) : après un import, des révélations réussies et une
 * révélation en erreur, AUCUNE clé de licence n'apparaît en clair :
 *   - ni en base (clés, imports, journal d'audit), y compris sous une autre casse ou sans tirets ;
 *   - ni dans les journaux de l'application (var/cache/test/journal.log en test) ;
 *   - ni dans les réponses de l'API, sauf la réponse de révélation, à son seul acheteur.
 */
#[Group('database')]
#[Group('redis')]
final class ClesLicenceSecuriteTest extends ClesWebTestCase
{
    protected static function adresseIp(): string
    {
        return '198.51.100.82';
    }

    public function testAucuneCleEnClairNiEnBaseNiDansLesJournauxNiDansLesReponses(): void
    {
        $journal = static::getContainer()->getParameter('kernel.cache_dir').'/journal.log';
        file_put_contents($journal, '');
        $offre = $this->creerOffre();
        $importees = [self::nouvelleCle(), self::nouvelleCle(), self::nouvelleCle()];
        $reponses = [];

        // Import (dont un doublon et une ligne invalide qui contient une clé valide entourée d'espaces internes)
        $this->importer($offre, implode("\n", [...$importees, $importees[0], 'X '.$importees[1]]), $this->jetonPour(self::GESTIONNAIRE));
        self::assertSame(201, $this->etat()['statut']);
        $reponses[] = (string) $this->client->getResponse()->getContent();

        // Révélations : réussie (acheteur), refusée (intrus), en erreur (chiffré altéré)
        $acheteur = $this->creerAcheteur();
        $vendue = self::nouvelleCle();
        $cleId = $this->commandeAvecCle($acheteur['id'], 'PAYEE', $vendue);
        $this->reveler($cleId, $this->jetonPour($this->creerAcheteur()['email']));
        self::assertSame(404, $this->etat()['statut']);
        $reponses[] = (string) $this->client->getResponse()->getContent();
        $alteree = self::nouvelleCle();
        $this->reveler($this->commandeAvecCle($acheteur['id'], 'PAYEE', $alteree, chiffreForce: "\x01".random_bytes(40)), $this->jetonPour($acheteur['email']));
        self::assertSame(500, $this->etat()['statut']);
        $reponses[] = (string) $this->client->getResponse()->getContent();
        $this->reveler($cleId, $this->jetonPour($acheteur['email']));
        self::assertSame($vendue, $this->etat()['corps']['cle'], 'seule réponse autorisée à contenir la clé');

        $base = $this->contenuDeLaBase($offre, $cleId);
        $journaux = (string) file_get_contents($journal);
        self::assertStringContainsString('Erreur interne', $journaux, 'les journaux ont bien été écrits pendant le test');

        foreach ([...$importees, $vendue] as $valeur) {
            foreach (self::variantes($valeur) as $variante) {
                self::assertStringNotContainsString($variante, $base, 'clé en clair dans la base');
                self::assertStringNotContainsString(bin2hex($variante), $base, 'clé en clair (hex) dans une colonne binaire');
                self::assertStringNotContainsString($variante, $journaux, 'clé en clair dans les journaux');
                foreach ($reponses as $reponse) {
                    self::assertStringNotContainsString($variante, $reponse, 'clé en clair dans une réponse de l\'API');
                }
            }
        }
    }

    public function testLaBaseNeStockeQueDesChiffresAuthentifies(): void
    {
        $offre = $this->creerOffre();
        $valeur = self::nouvelleCle();
        $this->importer($offre, $valeur."\n", $this->jetonPour(self::GESTIONNAIRE));

        $ligne = $this->connexion->fetchAssociative(
            "SELECT get_byte(cle_chiffree, 0) AS version, octet_length(cle_chiffree) AS taille, encode(empreinte, 'hex') AS empreinte FROM cle_licence WHERE offre_id = CAST(:o AS uuid)",
            ['o' => $offre],
        );
        self::assertIsArray($ligne);
        self::assertSame(1, $ligne['version']);
        self::assertSame(1 + 12 + 16 + \strlen($valeur), $ligne['taille'], 'IV et étiquette GCM présents');
        self::assertNotSame(hash('sha256', str_replace('-', '', $valeur)), $ligne['empreinte'], 'HMAC avec secret, pas un simple SHA-256 (sinon force brute possible)');
    }

    /** Tout ce que la base garde des clés, imports et révélations de ce test, en texte et en hexadécimal. */
    private function contenuDeLaBase(string $offre, string $cleId): string
    {
        $lignes = [
            ...$this->connexion->fetchAllAssociative(
                "SELECT k.*, encode(k.cle_chiffree, 'hex') AS h1, encode(k.empreinte, 'hex') AS h2 FROM cle_licence k WHERE k.offre_id = CAST(:o AS uuid) OR k.id = CAST(:k AS uuid)",
                ['o' => $offre, 'k' => $cleId],
            ),
            ...$this->connexion->fetchAllAssociative('SELECT * FROM import_cles ORDER BY cree_le DESC LIMIT 5'),
            ...$this->connexion->fetchAllAssociative("SELECT *, encode(ip_hachee, 'hex') AS h FROM journal_audit ORDER BY cree_le DESC LIMIT 20"),
        ];
        array_walk_recursive($lignes, static function (mixed &$v): void {
            $v = \is_resource($v) ? (string) stream_get_contents($v) : $v;
        });

        return (string) json_encode($lignes, \JSON_INVALID_UTF8_SUBSTITUTE).serialize($lignes);
    }

    /** @return list<string> la clé telle qu'importée, sans tirets, en minuscules */
    private static function variantes(string $valeur): array
    {
        return [$valeur, str_replace('-', '', $valeur), strtolower($valeur), strtolower(str_replace('-', '', $valeur))];
    }
}
