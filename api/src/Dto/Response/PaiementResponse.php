<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Service\Paiement\PaiementEffectue;

/**
 * Contrat de sortie du paiement : la commande telle qu'elle est maintenant (PAYEE ou A_VERIFIER) et un résumé du paiement.
 * Aucune donnée de carte hormis les quatre derniers chiffres ; aucune indication de la règle anti-fraude qui a
 * éventuellement mis la commande « à vérifier » (un fraudeur n'a pas à connaître les seuils).
 */
final class PaiementResponse
{
    /** @return array{commande: array<string, mixed>, paiement: array{statut: string, derniersChiffres: string, rejeu: bool}} */
    public static function depuis(PaiementEffectue $paiement): array
    {
        return [
            'commande' => CommandeResponse::detail($paiement->commande),
            'paiement' => ['statut' => 'ACCEPTE', 'derniersChiffres' => $paiement->derniersChiffres, 'rejeu' => $paiement->rejeu],
        ];
    }
}
