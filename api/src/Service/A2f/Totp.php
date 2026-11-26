<?php

declare(strict_types=1);

namespace App\Service\A2f;

/**
 * TOTP (RFC 6238) : SHA-1, 6 chiffres, pas de 30 secondes, compatible avec les applications d'authentification
 * courantes (Google Authenticator, Aegis, FreeOTP…). Implémentation : Infrastructure/Totp/TotpOtphp.
 */
interface Totp
{
    public const PAS = 30;            // secondes
    public const CHIFFRES = 6;
    /** Tolérance d'horloge : un pas avant et un pas après le pas courant (±30 s). */
    public const TOLERANCE = 1;

    /** Nouveau secret aléatoire de 160 bits, en base32 (32 caractères). */
    public function nouveauSecret(): string;

    /** URI otpauth:// du QR code (émetteur « SideKey », libellé = e-mail). */
    public function uriProvisionnement(string $secret, string $email): string;

    /**
     * Vérifie un code à 6 chiffres.
     *
     * @return int|null numéro du pas de 30 s dont le code est issu (pour interdire sa réutilisation), null si faux
     */
    public function verifier(string $secret, string $code): ?int;
}
