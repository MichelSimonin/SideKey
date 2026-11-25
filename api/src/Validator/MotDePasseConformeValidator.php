<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\Request\InscriptionRequest;
use App\Service\Auth\PolitiqueMotDePasse;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class MotDePasseConformeValidator extends ConstraintValidator
{
    public function __construct(private readonly PolitiqueMotDePasse $politique)
    {
    }

    public function validate(mixed $valeur, Constraint $contrainte): void
    {
        if (!$contrainte instanceof MotDePasseConforme) {
            throw new UnexpectedTypeException($contrainte, MotDePasseConforme::class);
        }
        if (!$valeur instanceof InscriptionRequest) {
            throw new UnexpectedTypeException($valeur, InscriptionRequest::class);
        }

        $violations = $this->politique->violations($valeur->motDePasse, $valeur->email, $valeur->prenom, $valeur->nom);
        if ([] !== $violations) {
            // Une seule erreur par champ dans le contrat 422 : la première (la plus informative) est renvoyée.
            $this->context->buildViolation($violations[0]->value)
                ->setParameter('{{ min }}', (string) PolitiqueMotDePasse::LONGUEUR_MIN)
                ->setParameter('{{ max }}', (string) PolitiqueMotDePasse::LONGUEUR_MAX)
                ->atPath('motDePasse')
                ->addViolation();
        }
    }
}
