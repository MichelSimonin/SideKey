<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Service\Catalogue\FiltresCatalogue;
use App\Service\Catalogue\Gestion\SaisieJeu;
use App\Service\Catalogue\Gestion\Slugueur;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST et PUT /api/admin/jeux. Liste blanche de champs : jaquette, offres, identifiant, dates de création… ne se
 * définissent pas ici (protection contre l'affectation de masse, un champ inconnu est ignoré). Le slug est facultatif :
 * dérivé du titre à la création, inchangé à la modification s'il est absent.
 */
final class JeuRequest
{
    /** @param list<string> $genres slugs de genre */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public readonly string $titre = '',

        #[Assert\Length(max: Slugueur::MAX)]
        public readonly ?string $slug = null,

        #[Assert\Regex('/^\d{4}-\d{2}-\d{2}$/')]
        public readonly ?string $dateSortie = null,

        #[Assert\NotNull]
        #[Assert\Choice(choices: FiltresCatalogue::PEGI)]
        public readonly ?int $pegi = null,

        #[Assert\NotBlank]
        #[Assert\Uuid]
        public readonly string $editeurId = '',

        public readonly bool $actif = true,

        #[Assert\Valid]
        public readonly DescriptionsRequest $descriptions = new DescriptionsRequest(),

        #[Assert\Count(max: SaisieJeu::GENRES_MAX)]
        #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 80)])]
        public readonly array $genres = [],
    ) {
    }

    /** Convertit après validation du format ; SaisieJeu rejoue les règles qui protègent la base. */
    public function versSaisie(): SaisieJeu
    {
        return new SaisieJeu(
            $this->titre,
            $this->slug,
            '' === $this->dateSortie ? null : $this->dateSortie,
            (int) $this->pegi,
            $this->editeurId,
            $this->actif,
            ['fr' => $this->descriptions->fr, 'en' => $this->descriptions->en],
            array_values($this->genres),
        );
    }
}
