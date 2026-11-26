<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Le client a déjà une commande en attente de paiement (409) : une seule à la fois, ce qui empêche un
 * double clic ou deux onglets de réserver deux fois le même panier. La référence permet d'y revenir.
 */
final class CommandeEnCoursException extends ExceptionMetier
{
    public function __construct(private readonly string $reference)
    {
        parent::__construct('error.commande_en_cours');
    }

    public function codeHttp(): int
    {
        return 409;
    }

    public function details(): array
    {
        return ['reference' => $this->reference];
    }
}
