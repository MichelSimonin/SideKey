<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Code TOTP ou code de secours faux, déjà utilisé, expiré, ou secret illisible (401). Un seul message : on ne dit pas
 * lequel. « raison » permet au front de distinguer un code erroné (champ du formulaire) d'un jeton intermédiaire
 * expiré (retour à la connexion), qui produit, lui, le 401 générique du pare-feu.
 */
final class CodeA2fInvalideException extends ExceptionMetier
{
    public function __construct()
    {
        parent::__construct('error.a2f_code_invalide');
    }

    public function codeHttp(): int
    {
        return 401;
    }

    public function details(): array
    {
        return ['raison' => 'code_invalide'];
    }
}
