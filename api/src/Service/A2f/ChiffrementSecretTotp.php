<?php

declare(strict_types=1);

namespace App\Service\A2f;

/**
 * Protection du secret TOTP au repos (CDC §9.4, colonne utilisateur.totp_secret_chiffre). Implémentation :
 * Infrastructure/Crypto/ChiffreurSecretTotp (AES-256-GCM, secret TOTP_ENC_KEY distinct de celui des clés de licence).
 * Le chiffré est lié au compte (données associées) : recopié sur un autre compte, il devient illisible.
 */
interface ChiffrementSecretTotp
{
    public function chiffrer(string $secret, string $utilisateurId): string;

    /** @throws SecretTotpIllisibleException chiffré altéré, autre compte, autre clé maître ou donnée factice */
    public function dechiffrer(string $chiffre, string $utilisateurId): string;
}
