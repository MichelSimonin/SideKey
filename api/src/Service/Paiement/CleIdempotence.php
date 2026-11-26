<?php

declare(strict_types=1);

namespace App\Service\Paiement;

use App\Exception\PaiementInvalideException;

/**
 * Clé d'idempotence d'une tentative de paiement (en-tête Idempotency-Key, US-32) : le client en fabrique une nouvelle par
 * tentative (UUID, 16 à 64 caractères) et la RENVOIE à l'identique pour rejouer la même requête sans payer deux fois.
 *
 * La clé stockée (colonne paiement.cle_idempotence, clé Redis idem:{…}) est une EMPREINTE de « client + clé » : deux
 * clients qui choisiraient la même valeur n'entrent jamais en collision, et un client ne peut pas savoir si la clé d'un
 * autre existe (pas de « clé déjà utilisée » qui trahirait un tiers). 64 caractères hexadécimaux = la taille de la colonne.
 */
final class CleIdempotence
{
    private const FORMAT = '/^[A-Za-z0-9_-]{16,64}$/';

    private function __construct(public readonly string $empreinte)
    {
    }

    public static function depuis(?string $brute, string $utilisateurId): self
    {
        if (null === $brute || 1 !== preg_match(self::FORMAT, $brute)) {
            throw new PaiementInvalideException(['Idempotency-Key' => 'error.paiement_cle_idempotence']);
        }

        return new self(hash('sha256', $utilisateurId."\n".$brute));
    }
}
