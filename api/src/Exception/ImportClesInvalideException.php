<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Fichier d'import de clés refusé en bloc, avant toute insertion : absent, vide, trop gros, trop de lignes,
 * mauvais encodage ou mauvais type (422, ou 413 pour la taille). Le message ne cite jamais le contenu.
 */
final class ImportClesInvalideException extends ExceptionMetier
{
    /** @param array<string, string|int> $parametres */
    public function __construct(string $cleMessage, array $parametres = [])
    {
        parent::__construct($cleMessage, $parametres);
    }

    public function codeHttp(): int
    {
        return 'error.import_fichier_trop_gros' === $this->cleMessage() ? 413 : 422;
    }
}
