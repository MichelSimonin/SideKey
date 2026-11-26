<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\Paiement\PaiementWebTestCase;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Group;

/**
 * Critère de sécurité de F-07 (CDC §11.6, ADR-0008) : « aucune donnée de carte stockée ». Après un paiement accepté, un refus,
 * des fonds insuffisants et un délai dépassé, AUCUN numéro de carte n'apparaît :
 *   - ni en base, dans AUCUNE table (pas seulement dans « paiement ») ;
 *   - ni dans Redis (noms de clés et valeurs) ;
 *   - ni dans les journaux de l'application (var/cache/test/journal.log en test) ;
 *   - ni dans les réponses de l'API, y compris les erreurs.
 * Seuls les quatre derniers chiffres sont conservés.
 */
#[Group('database')]
#[Group('redis')]
final class PaiementSecuriteTest extends PaiementWebTestCase
{
    private const EXPIRATION = '09/31';

    protected static function adresseIp(): string
    {
        return '198.51.100.94';
    }

    public function testAucunNumeroDeCarteNiEnBaseNiDansRedisNiDansLesJournauxNiDansLesReponses(): void
    {
        $journal = static::getContainer()->getParameter('kernel.cache_dir').'/journal.log';
        file_put_contents($journal, '');
        $acheteur = $this->acheteur();
        $reponses = [];

        foreach ([
            ['4000000000000002', 'carte refusée'],
            ['4000 0000 0000 9995', 'fonds insuffisants, saisi avec des espaces'],
            ['4000-0000-0000-0119', 'délai dépassé, saisi avec des tirets'],
            ['4242424242424242', 'accepté'],
        ] as [$numero]) {
            $reference = $this->commander($acheteur, $this->offreEnStock('19.99', 2));
            $reponses[] = json_encode($this->payer($reference, $acheteur, ['numeroCarte' => $numero, 'expiration' => self::EXPIRATION, 'cryptogramme' => '7391']), \JSON_THROW_ON_ERROR);
            if (!str_starts_with($numero, '4242')) {
                $this->payer($reference, $acheteur, $this->carte(self::OK));   // la commande se règle ensuite, pour libérer le client unique en attente
            }
        }
        // Une carte invalide et un numéro forgé : les erreurs de validation non plus ne renvoient pas la saisie
        $reference = $this->commander($acheteur, $this->offreEnStock());
        $reponses[] = json_encode($this->payer($reference, $acheteur, ['numeroCarte' => '4111111111111112', 'expiration' => self::EXPIRATION, 'cryptogramme' => '7391']), \JSON_THROW_ON_ERROR);

        $recherches = [
            '4242424242424242', '4000000000000002', '4000000000009995', '4000000000000119', '4111111111111112',
            '4242 4242 4242 4242', '4000 0000 0000 9995', '4000-0000-0000-0119', self::EXPIRATION,
        ];
        $surfaces = [
            'réponses de l\'API' => implode("\n", $reponses),
            'journaux de l\'application' => (string) file_get_contents($journal),
            'Redis' => $this->contenuDeRedis(),
            'base de données' => $this->contenuDeLaBase(),
        ];
        foreach ($surfaces as $nom => $contenu) {
            self::assertNotSame('', $contenu, $nom.' : contenu lu');
            foreach ($recherches as $recherche) {
                self::assertStringNotContainsString($recherche, $contenu, 'donnée de carte « '.$recherche.' » dans : '.$nom);
            }
        }

        // Seuls les quatre derniers chiffres sont conservés, et uniquement dans paiement.derniers_chiffres
        $derniers = array_map('strval', $this->connexion->fetchFirstColumn(
            'SELECT DISTINCT p.derniers_chiffres FROM paiement p JOIN commande c ON c.id = p.commande_id WHERE c.utilisateur_id = CAST(:u AS uuid) ORDER BY 1',
            ['u' => $acheteur['id']],
        ));
        self::assertSame(['0002', '0119', '4242', '9995'], $derniers);
    }

    public function testLesDonneesDeCarteNeSontPasDansLesTracesDePile(): void
    {
        // #[\SensitiveParameter] : une exception levée avec la carte en argument ne la montre pas dans sa trace
        $numero = '4242424242424241';   // Luhn faux : lève PaiementInvalideException depuis CartePaiement::depuis()
        try {
            \App\Service\Paiement\CartePaiement::depuis($numero, '12/30', '7391', new \DateTimeImmutable('2026-11-26'));
            self::fail('La carte aurait dû être refusée.');
        } catch (\Throwable $e) {
            self::assertStringNotContainsString($numero, $e->getTraceAsString());
            self::assertStringNotContainsString('7391', $e->getTraceAsString());
            // Selon php.ini, les arguments sont soit omis (zend.exception_ignore_args, cas de la production), soit présents mais masqués
            if (!filter_var(\ini_get('zend.exception_ignore_args'), \FILTER_VALIDATE_BOOLEAN)) {
                self::assertStringContainsString('SensitiveParameterValue', $e->getTraceAsString());
            }
        }
    }

    public function testUnRejeuMassifNeCreeJamaisDeSecondPaiementNiDeSecondeVente(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock('19.99', 3), 3);

        $codes = [];
        for ($i = 0; $i < 12; ++$i) {
            $codes[] = $this->payer($reference, $acheteur, cle: 'cle-du-rejeu-massif-0123')['statut'];
        }

        self::assertSame(array_fill(0, 12, 200), $codes, 'chaque rejeu renvoie la même réponse');
        self::assertSame(['ACCEPTE'], $this->statutsPaiements($reference));
        self::assertSame(3, $this->clesPar($reference, 'VENDUE'));
        self::assertSame(3, $this->redis->zCard('fraud:keys24h:'.$acheteur['id']), 'les clés ne sont comptées qu\'une fois');
        self::assertSame(1, $this->connexion->fetchOne("SELECT count(*) FROM journal_audit WHERE action = 'PAIEMENT_ACCEPTE' AND utilisateur_id = CAST(:u AS uuid)", ['u' => $acheteur['id']]), 'un seul paiement tracé');
    }

    public function testUnAttaquantNePeutPasPayerLaCommandeDUnAutreNiLuiVolerSaReponse(): void
    {
        $lea = $this->acheteur();
        $intrus = $this->acheteur();
        $reference = $this->commander($lea, $this->offreEnStock());

        $tentative = $this->payer($reference, $intrus, cle: 'cle-de-lintrus-0123456789');

        self::assertSame(404, $tentative['statut']);
        self::assertSame([], $this->statutsPaiements($reference));
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->statutCommande($reference));
        self::assertStringNotContainsString($lea['email'], json_encode($tentative, \JSON_THROW_ON_ERROR));
    }

    public function testUnPaiementNeChangeJamaisLeStatutParLeCorpsDeLaRequete(): void
    {
        $acheteur = $this->acheteur();
        $reference = $this->commander($acheteur, $this->offreEnStock());

        $reponse = $this->payer($reference, $acheteur, $this->carte(self::REFUSEE) + ['statut' => 'ACCEPTE', 'accepte' => true, 'commande' => ['statut' => 'PAYEE']]);

        self::assertSame(402, $reponse['statut'], 'ni un statut ni un indicateur envoyés par le client ne contournent le prestataire');
        self::assertSame('EN_ATTENTE_PAIEMENT', $this->statutCommande($reference));
        self::assertSame(0, $this->clesPar($reference, 'VENDUE'));
    }

    // ---- Outils -------------------------------------------------------------------------------------------------

    /** Noms de clés et valeurs textuelles de tout Redis de test (le jeu de test est petit). */
    private function contenuDeRedis(): string
    {
        $contenu = [];
        $iterateur = null;
        while (false !== ($cles = $this->redis->scan($iterateur, '*', 500))) {
            foreach ($cles as $cle) {
                $contenu[] = $cle;
                $type = $this->redis->type($cle);
                if (\Redis::REDIS_STRING === $type) {
                    $contenu[] = (string) $this->redis->get($cle);
                } elseif (\Redis::REDIS_ZSET === $type) {
                    $contenu[] = implode(' ', (array) $this->redis->zRange($cle, 0, -1));
                } elseif (\Redis::REDIS_SET === $type) {
                    $contenu[] = implode(' ', (array) $this->redis->sMembers($cle));
                } elseif (\Redis::REDIS_HASH === $type) {
                    $contenu[] = json_encode($this->redis->hGetAll($cle), \JSON_THROW_ON_ERROR);
                }
            }
            if (0 === $iterateur) {
                break;
            }
        }

        return implode("\n", $contenu);
    }

    /** Chaque ligne de chaque table, en texte : pas seulement la table des paiements. */
    private function contenuDeLaBase(): string
    {
        $contenu = [];
        foreach ($this->connexion->fetchFirstColumn("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE'") as $table) {
            try {
                $contenu[] = (string) $this->connexion->fetchOne(\sprintf('SELECT coalesce(string_agg(t::text, E\'\n\'), \'\') FROM "%s" t', str_replace('"', '', (string) $table)));
            } catch (DriverException) {
                // table non lisible par le rôle applicatif : elle ne peut pas non plus avoir reçu de carte de lui
            }
        }

        return implode("\n", $contenu);
    }
}
