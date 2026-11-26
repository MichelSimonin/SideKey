<?php

declare(strict_types=1);

namespace App\Dto\Response;

use App\Service\Auth\ResultatConnexion;
use App\Service\Auth\Session;

/**
 * Contrat de sortie des routes de connexion et de rafraîchissement.
 * Le jeton de rafraîchissement n'apparaît jamais dans le corps : il voyage uniquement par cookie HttpOnly.
 */
final class SessionResponse
{
    /**
     * @return array{etape: 'connecte', jetonAcces: string, typeJeton: 'Bearer', expireDans: int, utilisateur: array<string, mixed>}
     */
    public static function depuis(Session $session): array
    {
        return [
            'etape' => 'connecte',
            'jetonAcces' => $session->jetonAcces,
            'typeJeton' => 'Bearer',
            'expireDans' => $session->expireDans,
            'utilisateur' => UtilisateurResponse::depuis($session->compte),
        ];
    }

    /**
     * Seconde étape requise (A2F, F-04) : ni jeton d'accès ni utilisateur, seulement le jeton intermédiaire. « a2f » : saisie d'un
     * code ; « a2f_enrolement » : A2F obligatoire mais à configurer.
     *
     * @return array{etape: 'a2f'|'a2f_enrolement', jetonIntermediaire: string, expireDans: int}
     */
    public static function etapeA2f(ResultatConnexion $resultat): array
    {
        return [
            'etape' => $resultat->enrolement ? 'a2f_enrolement' : 'a2f',
            'jetonIntermediaire' => (string) $resultat->jetonIntermediaire,
            'expireDans' => $resultat->intermediaireExpireDans,
        ];
    }
}
