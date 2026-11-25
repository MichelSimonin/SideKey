<?php

declare(strict_types=1);

namespace App\Exception;

/** Un compte existe déjà avec cet e-mail (409). */
final class EmailDejaUtiliseException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.email_deja_utilise');
    }

    public function codeHttp(): int
    {
        return 409;
    }
}
