<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

/**
 * Stockage des jaquettes téléversées (F-09). Implémentation : Infrastructure/Media/StockageJaquettesFichiers (dossier
 * public/media/jaquettes, servi tel quel par le proxy, sans exécution de code).
 */
interface StockageJaquettes
{
    /** Chemin relatif stocké en base (« jaquettes/{32 hexadécimaux}.webp ») : jamais le nom envoyé par l'utilisateur. */
    public const CHEMIN = '/^jaquettes\/[0-9a-f]{32}\.webp$/';

    /** @return string chemin relatif du fichier écrit, au nom régénéré */
    public function ecrire(string $contenu): string;

    /** Supprime un fichier écrit par ce stockage ; sans effet pour tout autre chemin (jaquettes du jeu d'essai, valeur forgée). */
    public function supprimer(string $chemin): void;
}
