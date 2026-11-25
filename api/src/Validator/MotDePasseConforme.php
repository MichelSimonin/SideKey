<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Contrainte de classe : le mot de passe d'une inscription respecte la politique du CDC §11.4.
 * Contrainte de classe (et non de propriété) parce que la règle « ne contient pas l'identité » compare le
 * mot de passe aux autres champs. Les messages sont les clés du domaine « validators » (FR et EN).
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class MotDePasseConforme extends Constraint
{
    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
