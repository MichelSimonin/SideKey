<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;
use App\Service\Panier\PanierId;

/** Identifiant de ressource venu d'une URL ou d'un corps : un UUID, sinon « introuvable » (jamais une erreur de conversion côté base). */
final class Identifiant
{
    public static function exiger(string $id): string
    {
        if (!PanierId::estUuid($id)) {
            throw CatalogueException::introuvable();
        }

        return strtolower($id);
    }
}
