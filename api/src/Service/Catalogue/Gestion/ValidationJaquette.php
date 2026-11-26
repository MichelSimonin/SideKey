<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\JaquetteInvalideException;

/**
 * Validation d'une jaquette téléversée (F-09, critère « upload sécurisé : type, taille, nom régénéré »).
 *
 * Le type n'est jamais déduit du nom ni du Content-Type envoyés par le navigateur (falsifiables) : on lit le contenu.
 * Format accepté : WebP uniquement. Le conteneur RIFF est contrôlé (signature, longueur déclarée = longueur réelle), puis
 * getimagesizefromstring() — qui n'exige pas GD — confirme le type et donne les dimensions, bornées pour écarter les
 * « bombes de décompression » et les vignettes inexploitables.
 *
 * Limite assumée (documentée au dossier de projet) : sans bibliothèque de traitement d'image, l'image n'est pas ré-encodée ;
 * les métadonnées éventuelles (EXIF/XMP) sont conservées. Le fichier est servi comme image statique, avec nosniff et une
 * CSP restrictive, et son nom est régénéré : un contenu forgé ne s'exécute jamais.
 */
final class ValidationJaquette
{
    public const OCTETS_MAX = 524_288;   // 512 Kio
    public const OCTETS_MIN = 32;
    public const COTE_MIN = 100;
    public const COTE_MAX = 4_000;

    /**
     * @throws JaquetteInvalideException
     */
    public static function valider(string $contenu): void
    {
        $taille = \strlen($contenu);
        if (0 === $taille) {
            throw new JaquetteInvalideException(JaquetteInvalideException::ABSENTE);
        }
        if ($taille > self::OCTETS_MAX) {
            throw new JaquetteInvalideException(JaquetteInvalideException::TROP_LOURDE, ['%max%' => intdiv(self::OCTETS_MAX, 1024)]);
        }
        if ($taille < self::OCTETS_MIN || 'RIFF' !== substr($contenu, 0, 4) || 'WEBP' !== substr($contenu, 8, 4)) {
            throw new JaquetteInvalideException(JaquetteInvalideException::TYPE);
        }
        $declaree = unpack('V', substr($contenu, 4, 4));
        if (false === $declaree || $declaree[1] + 8 !== $taille) {
            throw new JaquetteInvalideException(JaquetteInvalideException::TYPE);   // tronqué ou suivi de données parasites
        }

        // @ : un contenu altéré fait émettre un avertissement ; on le traduit en refus propre, pas en erreur 500.
        $infos = @getimagesizefromstring($contenu);
        if (false === $infos || 'image/webp' !== ($infos['mime'] ?? null)) {
            throw new JaquetteInvalideException(JaquetteInvalideException::TYPE);
        }
        [$largeur, $hauteur] = $infos;
        foreach ([$largeur, $hauteur] as $cote) {
            if ($cote < self::COTE_MIN || $cote > self::COTE_MAX) {
                throw new JaquetteInvalideException(JaquetteInvalideException::DIMENSIONS, ['%min%' => self::COTE_MIN, '%max%' => self::COTE_MAX]);
            }
        }
    }
}
