<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Un paiement de cette commande est déjà en cours (409) : double clic, deux onglets ou deux clés différentes. L'index
 * unique de la base garantit qu'un seul atteint le prestataire ; l'autre n'est pas débité.
 */
final class PaiementEnCoursException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.paiement_en_cours');
    }

    public function codeHttp(): int
    {
        return 409;
    }
}
