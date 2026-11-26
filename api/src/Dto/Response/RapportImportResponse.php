<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Service\Cles\RapportImport;

/**
 * Rapport d'import renvoyé au back-office (écran E13). Les lignes refusées sont désignées par leur numéro et
 * leur motif, jamais par leur valeur : le gestionnaire ne voit aucune clé, même la sienne (BX-13).
 */
final class RapportImportResponse
{
    /**
     * @return array{id: string, fichier: string, statut: string, lignes: int, acceptees: int, doublons: int, erreurs: int,
     *               refus: list<array{ligne: int, motif: string}>}
     */
    public static function depuis(RapportImport $rapport): array
    {
        return [
            'id' => $rapport->id,
            'fichier' => $rapport->nomFichier,
            'statut' => $rapport->statut(),
            'lignes' => $rapport->nbLignes(),
            'acceptees' => $rapport->nbAcceptees(),
            'doublons' => $rapport->nbDoublons(),
            'erreurs' => $rapport->nbErreurs(),
            'refus' => $rapport->refus(),
        ];
    }
}
