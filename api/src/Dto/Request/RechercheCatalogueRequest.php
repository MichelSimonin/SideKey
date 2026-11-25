<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\TriCatalogue;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Paramètres de GET /api/jeux (US-01 à US-05). Toute valeur est contrôlée ici (format, bornes, liste blanche) avant
 * d'atteindre le métier, qui la revérifie. Les noms des propriétés sont ceux des paramètres d'URL, y compris
 * prix_min et prix_max : les erreurs 422 désignent ainsi le paramètre fautif.
 *
 * Exemple : /api/jeux?q=aube&plateforme[]=STEAM&plateforme[]=PSN&genre[]=action&prix_max=30&pegi=16&tri=prix_asc&page=2
 */
final class RechercheCatalogueRequest
{
    private const PRIX = '/^\d{1,6}(\.\d{1,2})?$/';

    /**
     * @param list<string> $plateforme
     * @param list<string> $genre
     */
    public function __construct(
        #[Assert\Length(max: FiltresCatalogue::RECHERCHE_MAX)]
        public readonly ?string $q = null,

        #[Assert\Count(max: FiltresCatalogue::VALEURS_MAX)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[A-Z0-9_]{1,12}$/')])]
        public readonly array $plateforme = [],

        #[Assert\Count(max: FiltresCatalogue::VALEURS_MAX)]
        #[Assert\All([new Assert\Type('string'), new Assert\Regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'), new Assert\Length(max: 80)])]
        public readonly array $genre = [],

        #[Assert\Regex(self::PRIX)]
        public readonly ?string $prix_min = null,

        #[Assert\Regex(self::PRIX)]
        public readonly ?string $prix_max = null,

        #[Assert\Choice(choices: FiltresCatalogue::PEGI)]
        public readonly ?int $pegi = null,

        #[Assert\Choice(callback: [TriCatalogue::class, 'valeurs'])]
        public readonly string $tri = 'titre',

        #[Assert\Range(min: 1, max: FiltresCatalogue::PAGE_MAX)]
        public readonly int $page = 1,
    ) {
    }

    #[Assert\Callback]
    public function verifierFourchetteDePrix(ExecutionContextInterface $contexte): void
    {
        if (null !== $this->prix_min && null !== $this->prix_max
            && 1 === preg_match(self::PRIX, $this->prix_min) && 1 === preg_match(self::PRIX, $this->prix_max)
            && self::centimes($this->prix_min) > self::centimes($this->prix_max)) {
            $contexte->buildViolation('price.range')->atPath('prix_min')->addViolation();
        }
    }

    /** Convertit après validation : le DTO ne produit que des valeurs que le métier accepte. */
    public function versFiltres(): FiltresCatalogue
    {
        return new FiltresCatalogue(
            $this->q,
            array_values($this->plateforme),
            array_values($this->genre),
            null === $this->prix_min ? null : self::centimes($this->prix_min),
            null === $this->prix_max ? null : self::centimes($this->prix_max),
            $this->pegi,
            TriCatalogue::from($this->tri),
            $this->page,
        );
    }

    /** « 12.5 » → 1250 sans calcul à virgule flottante. */
    private static function centimes(string $prix): int
    {
        [$euros, $cents] = array_pad(explode('.', $prix, 2), 2, '0');

        return (int) $euros * 100 + (int) str_pad($cents, 2, '0');
    }
}
