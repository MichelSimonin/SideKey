<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Validator\MotDePasseConforme;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/inscription (US-20). Aucun champ « rôle », « actif » ou « emailVerifie » : le client ne
 * peut pas les imposer, et un champ inconnu dans le JSON est ignoré (pas de mass assignment).
 */
#[MotDePasseConforme]
final class InscriptionRequest
{
    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 254)]
        #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_HTML5)]
        public readonly string $email = '',

        // La politique (12 à 128 caractères, tous caractères acceptés) est portée par MotDePasseConforme.
        public readonly string $motDePasse = '',

        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 80)]
        #[Assert\Regex(pattern: '/^[^<>\p{C}]*$/u', message: 'name.forbidden_characters')]
        public readonly string $prenom = '',

        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 80)]
        #[Assert\Regex(pattern: '/^[^<>\p{C}]*$/u', message: 'name.forbidden_characters')]
        public readonly string $nom = '',

        #[Assert\Choice(choices: ['fr', 'en'])]
        public readonly string $langue = 'fr',

        // Consentement RGPD exprès (US-20). Le schéma T-01 n'a pas de colonne dédiée : il est exigé, pas stocké.
        #[Assert\IsTrue(message: 'consent.required')]
        public readonly bool $consentement = false,
    ) {
    }
}
