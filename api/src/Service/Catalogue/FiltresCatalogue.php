<?php

declare(strict_types=1);

namespace App\Service\Catalogue;

/**
 * Critères de recherche du catalogue, déjà validés par la couche présentation (DTO) et revérifiés ici :
 * le constructeur normalise (tri des listes, doublons retirés) et borne les valeurs.
 * Prix en centimes (entiers) : aucun calcul à virgule flottante.
 */
final class FiltresCatalogue
{
    public const PAR_PAGE = 20;                // US-01
    public const PAGE_MAX = 10_000;
    public const RECHERCHE_MAX = 100;
    public const VALEURS_MAX = 10;             // plateformes ou genres cochés
    public const PEGI = [3, 7, 12, 16, 18];    // ck_jeu_pegi

    /** @var list<string> */
    public readonly array $plateformes;
    /** @var list<string> */
    public readonly array $genres;
    public readonly ?string $recherche;

    /**
     * @param list<string> $plateformes codes de plateforme (STEAM, PSN…)
     * @param list<string> $genres      slugs de genre
     * @param int|null     $pegi        âge maximum : les jeux dont le PEGI est inférieur ou égal
     */
    public function __construct(
        ?string $recherche = null,
        array $plateformes = [],
        array $genres = [],
        public readonly ?int $prixMinCentimes = null,
        public readonly ?int $prixMaxCentimes = null,
        public readonly ?int $pegi = null,
        public readonly TriCatalogue $tri = TriCatalogue::Titre,
        public readonly int $page = 1,
    ) {
        $recherche = null === $recherche ? null : trim($recherche);
        $this->recherche = '' === $recherche || null === $recherche ? null : mb_substr($recherche, 0, self::RECHERCHE_MAX);
        $this->plateformes = self::normaliser($plateformes, '/^[A-Z0-9_]{1,12}$/');
        $this->genres = self::normaliser($genres, '/^[a-z0-9]+(?:-[a-z0-9]+)*$/');

        if ($page < 1 || $page > self::PAGE_MAX) {
            throw new \InvalidArgumentException('Numéro de page hors bornes.');
        }
        if (null !== $pegi && !\in_array($pegi, self::PEGI, true)) {
            throw new \InvalidArgumentException('Classification PEGI inconnue.');
        }
        foreach ([$prixMinCentimes, $prixMaxCentimes] as $prix) {
            if (null !== $prix && ($prix < 0 || $prix > 100_000_000)) {
                throw new \InvalidArgumentException('Prix hors bornes.');
            }
        }
        if (null !== $prixMinCentimes && null !== $prixMaxCentimes && $prixMinCentimes > $prixMaxCentimes) {
            throw new \InvalidArgumentException('Prix minimum supérieur au prix maximum.');
        }
    }

    public function decalage(): int
    {
        return ($this->page - 1) * self::PAR_PAGE;
    }

    /**
     * Empreinte stable des critères, pour la clé de cache d'une liste : deux requêtes équivalentes (ordre des cases
     * cochées, espaces) donnent la même clé ; seule une empreinte hexadécimale entre dans la clé Redis.
     */
    public function empreinte(): string
    {
        return hash('sha256', json_encode([
            'q' => null === $this->recherche ? null : mb_strtolower($this->recherche),
            'p' => $this->plateformes,
            'g' => $this->genres,
            'min' => $this->prixMinCentimes,
            'max' => $this->prixMaxCentimes,
            'pegi' => $this->pegi,
            'tri' => $this->tri->value,
            'page' => $this->page,
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $valeurs
     *
     * @return list<string>
     */
    private static function normaliser(array $valeurs, string $format): array
    {
        $propres = [];
        foreach ($valeurs as $valeur) {
            if (1 !== preg_match($format, $valeur)) {
                throw new \InvalidArgumentException('Valeur de filtre invalide.');
            }
            $propres[$valeur] = $valeur;
        }
        if (\count($propres) > self::VALEURS_MAX) {
            throw new \InvalidArgumentException('Trop de valeurs de filtre.');
        }
        ksort($propres);

        return array_values($propres);
    }
}
