<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Media;

use App\Infrastructure\Media\StockageJaquettesFichiers;
use App\Service\Catalogue\Gestion\StockageJaquettes;
use PHPUnit\Framework\TestCase;

/** Le nom du fichier est régénéré ; seuls les fichiers de ce stockage sont supprimables. */
final class StockageJaquettesFichiersTest extends TestCase
{
    private string $racine;

    protected function setUp(): void
    {
        $this->racine = sys_get_temp_dir().'/sk-media-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->racine.'/jaquettes') ?: [] as $nom) {
            if (is_file($this->racine.'/jaquettes/'.$nom)) {
                unlink($this->racine.'/jaquettes/'.$nom);
            }
        }
        @rmdir($this->racine.'/jaquettes');
        @rmdir($this->racine);
    }

    public function testEcritSousUnNomRegenereEtCreeLeDossier(): void
    {
        $stockage = new StockageJaquettesFichiers($this->racine);

        $chemin = $stockage->ecrire('contenu');
        $autre = $stockage->ecrire('contenu');

        self::assertMatchesRegularExpression(StockageJaquettes::CHEMIN, $chemin);
        self::assertNotSame($chemin, $autre, 'jamais deux fois le même nom : aucun écrasement');
        self::assertSame('contenu', file_get_contents($this->racine.'/'.$chemin));
        self::assertSame([], glob($this->racine.'/jaquettes/.*.tmp') ?: [], 'aucun fichier temporaire laissé');
    }

    public function testSupprimeSesFichiersEtIgnoreTouteAutreValeur(): void
    {
        $stockage = new StockageJaquettesFichiers($this->racine);
        $chemin = $stockage->ecrire('contenu');
        $voisin = $this->racine.'/voisin.txt';
        file_put_contents($voisin, 'à conserver');

        foreach (['../voisin.txt', 'jaquettes/../voisin.txt', '/etc/passwd', 'jaquettes/'.str_repeat('A', 32).'.webp', 'jaquettes/x.php', ''] as $forge) {
            $stockage->supprimer($forge);
        }
        self::assertFileExists($voisin, 'une valeur forgée ne supprime rien hors du stockage');
        self::assertFileExists($this->racine.'/'.$chemin);

        $stockage->supprimer($chemin);
        self::assertFileDoesNotExist($this->racine.'/'.$chemin);
        $stockage->supprimer($chemin);   // déjà supprimé : sans effet, sans erreur
        unlink($voisin);
    }
}
