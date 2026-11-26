<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Verrouillage optimiste d'une offre (CDC §10.2, BX-14) : un collègue a modifié l'offre depuis le chargement du formulaire
 * (409). La réponse porte la version actuelle : le front propose de recharger, sans écraser en silence le travail d'autrui.
 */
final class ConflitOffreException extends ExceptionMetier
{
    public function __construct(private readonly int $versionActuelle)
    {
        parent::__construct('error.offre_conflit');
    }

    public function codeHttp(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['versionActuelle' => $this->versionActuelle];
    }
}
