<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/commandes/{reference}/paiement : la carte, rien d'autre. Ni montant (calculé par le serveur, RG-11), ni
 * identifiant de commande (dans l'URL), ni clé d'idempotence (en-tête Idempotency-Key). Un champ en trop est ignoré.
 *
 * Paiement SIMULÉ (ADR-0008) : le numéro de carte est un numéro de test. Ces valeurs sont marquées sensibles pour ne jamais
 * apparaître dans une trace de pile, et ne sont ni stockées ni journalisées (seuls les quatre derniers chiffres le sont).
 */
final class PayerCommandeRequest
{
    public function __construct(
        #[\SensitiveParameter]
        #[Assert\NotBlank]
        #[Assert\Length(max: 23)]
        #[Assert\Regex('/^[0-9 -]+$/')]
        public readonly string $numeroCarte = '',

        #[\SensitiveParameter]
        #[Assert\NotBlank]
        #[Assert\Regex('/^(0[1-9]|1[0-2])\/\d{2}$/')]
        public readonly string $expiration = '',

        #[\SensitiveParameter]
        #[Assert\NotBlank]
        #[Assert\Regex('/^\d{3,4}$/')]
        public readonly string $cryptogramme = '',
    ) {
    }
}
