<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Étape A2F demandée alors que l'état du compte ne la permet pas (409) : enrôlement d'un compte déjà protégé,
 * vérification d'un compte sans A2F, confirmation sans enrôlement commencé.
 */
final class A2fEtatIncompatibleException extends ExceptionMetier
{
    public const DEJA_ACTIVE = 'error.a2f_deja_active';
    public const NON_ACTIVE = 'error.a2f_non_active';
    public const ENROLEMENT_ABSENT = 'error.a2f_enrolement_absent';

    public function __construct(string $cleMessage)
    {
        parent::__construct($cleMessage);
    }

    public function codeHttp(): int
    {
        return 409;
    }
}
