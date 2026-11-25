<?php

declare(strict_types=1);

namespace App\Security\Jwt;

use App\Security\Utilisateur;
use App\Service\Auth\Compte;
use App\Service\Auth\EmetteurJetonAcces;
use App\Service\Auth\TypeJeton;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Émet les JWT signés RS256 par LexikJWTAuthenticationBundle (clé privée hors dépôt, config/jwt/).
 *
 * Revendications : sub (UUID du compte), jti (révocation), fam (session), typ (portée), iat, exp, roles.
 * Aucune donnée personnelle au-delà de l'identifiant : l'e-mail et le nom ne figurent pas dans le jeton.
 */
final class LexikEmetteurJetonAcces implements EmetteurJetonAcces
{
    public function __construct(
        private readonly JWTTokenManagerInterface $jwt,
        private readonly ClockInterface $horloge,
    ) {
    }

    public function emettre(Compte $compte, TypeJeton $type, string $jti, string $familleId, int $dureeSecondes): string
    {
        $maintenant = $this->horloge->now()->getTimestamp();

        return $this->jwt->createFromPayload(new Utilisateur($compte), [
            'jti' => $jti,
            'fam' => $familleId,
            'typ' => $type->value,
            'iat' => $maintenant,
            'exp' => $maintenant + $dureeSecondes,
        ]);
    }
}
