<?php

declare(strict_types=1);

namespace App\Tests\Support\Catalogue;

/**
 * Images WebP minimales pour les tests d'upload (aucune extension de traitement d'image n'est installée). Un conteneur RIFF
 * VP8L bien formé suffit : PHP en lit le type et les dimensions, exactement ce que contrôle ValidationJaquette.
 */
final class FabriqueWebp
{
    public static function creer(int $largeur = 300, int $hauteur = 400): string
    {
        // VP8L : signature 0x2F, puis 14 bits (largeur - 1), 14 bits (hauteur - 1), alpha et version.
        $charge = "\x2f".pack('V', ($largeur - 1) | (($hauteur - 1) << 14)).str_repeat("\x00", 8);
        $morceau = 'VP8L'.pack('V', \strlen($charge)).$charge;
        if (1 === \strlen($charge) % 2) {
            $morceau .= "\x00";
        }

        return 'RIFF'.pack('V', 4 + \strlen($morceau)).'WEBP'.$morceau;
    }
}
