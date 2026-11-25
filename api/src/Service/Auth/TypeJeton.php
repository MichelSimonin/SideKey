<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Portée d'un JWT, inscrite dans la revendication « typ ».
 *  - Acces : jeton définitif, accepté par le pare-feu de l'API.
 *  - A2f   : jeton intermédiaire de la connexion en deux étapes (CDC §11.4) ; il ne donne accès qu'à la
 *            saisie du code TOTP (F-04) et est refusé partout ailleurs.
 */
enum TypeJeton: string
{
    case Acces = 'access';
    case A2f = 'a2f';
}
