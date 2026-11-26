<?php

declare(strict_types=1);

namespace App\Exception;

/** Image refusée au téléversement (422) : absente, trop lourde, d'un type non accepté, altérée ou aux dimensions hors bornes. */
final class JaquetteInvalideException extends ExceptionMetier
{
    public const ABSENTE = 'error.jaquette_absente';
    public const TROP_LOURDE = 'error.jaquette_taille';
    public const TYPE = 'error.jaquette_type';
    public const DIMENSIONS = 'error.jaquette_dimensions';

    /** @param array<string, string|int> $parametres */
    public function __construct(string $cleMessage, array $parametres = [])
    {
        parent::__construct($cleMessage, $parametres);
    }

    public function codeHttp(): int
    {
        return 422;
    }
}
