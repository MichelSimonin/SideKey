<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;
use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Panier\PanierId;

/**
 * Données saisies pour créer ou modifier un jeu. Le DTO de la couche présentation a déjà validé le format ; le constructeur
 * rejoue les règles qui protègent la base (défense en profondeur) : titre, PEGI, éditeur, descriptions de 10 à 4 000
 * caractères dans les deux langues (ck_jt_description), genres, slug. Un échec lève un 422 par champ.
 */
final class SaisieJeu
{
    public const DESCRIPTION_MIN = 10;
    public const DESCRIPTION_MAX = 4000;
    public const GENRES_MAX = 10;

    public readonly string $titre;
    public readonly ?string $slug;
    public readonly string $editeurId;
    /** @var array{fr: string, en: string} */
    public readonly array $descriptions;
    /** @var list<string> */
    public readonly array $genres;

    /**
     * @param array{fr: string, en: string} $descriptions
     * @param list<string>                  $genres       slugs de genre
     */
    public function __construct(
        string $titre,
        ?string $slug,
        public readonly ?string $dateSortie,
        public readonly int $pegi,
        string $editeurId,
        public readonly bool $actif,
        array $descriptions,
        array $genres,
    ) {
        $erreurs = [];

        $this->titre = trim($titre);
        if ('' === $this->titre || mb_strlen($this->titre) > 200 || 1 === preg_match('/[<>\p{C}]/u', $this->titre)) {
            $erreurs['titre'] = 'error.invalid_value';
        }

        $slug = null === $slug || '' === trim($slug) ? null : trim($slug);
        if (null !== $slug && !Slugueur::valide($slug)) {
            $erreurs['slug'] = 'error.invalid_value';
        }
        $this->slug = $slug;

        if (null !== $dateSortie) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateSortie);
            if (false === $date || $date->format('Y-m-d') !== $dateSortie) {
                $erreurs['dateSortie'] = 'error.invalid_value';
            }
        }

        if (!\in_array($pegi, FiltresCatalogue::PEGI, true)) {
            $erreurs['pegi'] = 'error.invalid_value';
        }

        $this->editeurId = strtolower($editeurId);
        if (!PanierId::estUuid($this->editeurId)) {
            $erreurs['editeurId'] = 'error.invalid_value';
        }

        $propres = ['fr' => '', 'en' => ''];
        foreach (['fr', 'en'] as $langue) {
            $texte = trim($descriptions[$langue] ?? '');
            $longueur = mb_strlen($texte);
            // Ni balise HTML ni caractère de contrôle (hors saut de ligne et tabulation) : le contenu est du texte.
            if ($longueur < self::DESCRIPTION_MIN || $longueur > self::DESCRIPTION_MAX || 1 === preg_match('/<[a-zA-Z\/!]|[\x00-\x08\x0B\x0C\x0E-\x1F]/', $texte)) {
                $erreurs['descriptions.'.$langue] = 'error.invalid_value';
            }
            $propres[$langue] = $texte;
        }
        $this->descriptions = $propres;

        $uniques = [];
        foreach ($genres as $genre) {
            if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $genre) || \strlen($genre) > 80) {
                $erreurs['genres'] = 'error.invalid_value';
                break;
            }
            $uniques[$genre] = $genre;
        }
        if (\count($uniques) > self::GENRES_MAX) {
            $erreurs['genres'] = 'error.invalid_value';
        }
        $this->genres = array_values($uniques);

        if ([] !== $erreurs) {
            throw CatalogueException::invalide($erreurs);
        }
    }
}
