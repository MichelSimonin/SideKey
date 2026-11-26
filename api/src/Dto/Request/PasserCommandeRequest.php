<?php

declare(strict_types=1);

namespace App\Dto\Request;

/**
 * Corps de POST /api/commandes. Le client n'envoie ni offre, ni quantité, ni prix : la commande est
 * faite de son panier, relu en base (RG-11). Seule la déclaration de majorité vient de lui (RG-13).
 */
final class PasserCommandeRequest
{
    public function __construct(
        public readonly bool $confirmeMajeur = false,
    ) {
    }
}
