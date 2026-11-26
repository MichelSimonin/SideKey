<?php

declare(strict_types=1);

namespace App\Infrastructure\Media;

use App\Service\Catalogue\Gestion\StockageJaquettes;

/**
 * Jaquettes sur disque (dossier public/media/jaquettes, servi par le proxy sous /media/). Le nom du fichier est entièrement
 * régénéré (16 octets aléatoires, 32 hexadécimaux, extension fixe) : le nom envoyé par l'utilisateur n'est jamais utilisé,
 * donc ni traversée de répertoire, ni double extension, ni écrasement d'un fichier existant.
 */
final class StockageJaquettesFichiers implements StockageJaquettes
{
    public function __construct(private readonly string $racine)
    {
    }

    public function ecrire(string $contenu): string
    {
        $dossier = $this->racine.'/jaquettes';
        if (!is_dir($dossier) && !@mkdir($dossier, 0o755, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Dossier des jaquettes inutilisable.');
        }

        $nom = bin2hex(random_bytes(16)).'.webp';
        $cible = $dossier.'/'.$nom;
        $temporaire = $dossier.'/.'.bin2hex(random_bytes(8)).'.tmp';

        // Écriture atomique : un lecteur ne voit jamais un fichier à moitié écrit.
        if (false === @file_put_contents($temporaire, $contenu, \LOCK_EX)) {
            throw new \RuntimeException('Écriture de la jaquette impossible.');
        }
        @chmod($temporaire, 0o644);
        if (!@rename($temporaire, $cible)) {
            @unlink($temporaire);

            throw new \RuntimeException('Écriture de la jaquette impossible.');
        }

        return 'jaquettes/'.$nom;
    }

    public function supprimer(string $chemin): void
    {
        // Seuls les fichiers de ce stockage sont supprimables : une valeur forgée en base ne peut viser un autre fichier.
        if (1 !== preg_match(self::CHEMIN, $chemin)) {
            return;
        }
        @unlink($this->racine.'/'.$chemin);
    }
}
