<?php

declare(strict_types=1);

namespace App\Infrastructure\Totp;

use App\Service\A2f\Totp;
use OTPHP\TOTP as Otp;
use Psr\Clock\ClockInterface;

/**
 * TOTP par la bibliothèque spomky-labs/otphp (RFC 4226 et 6238), l'horloge étant injectée (testable).
 * Paramètres standard : SHA-1, 6 chiffres, 30 s : ceux que les applications d'authentification supposent.
 *
 * La vérification parcourt les pas -1, 0 et +1 et compare en temps constant (hash_equals) : on connaît le pas
 * exact d'où vient le code, ce qui permet d'en interdire la réutilisation (UsageUniqueTotp).
 */
final class TotpOtphp implements Totp
{
    private const EMETTEUR = 'SideKey';
    private const OCTETS_SECRET = 20;

    public function __construct(private readonly ClockInterface $horloge)
    {
    }

    public function nouveauSecret(): string
    {
        // 160 bits (RFC 4226 §4) : 32 caractères en base32, saisissables à la main. La taille par défaut de la bibliothèque
        // (1024 bits, 103 caractères) dépasse ce que certaines applications acceptent.
        return Otp::generate($this->horloge, self::OCTETS_SECRET)->getSecret();
    }

    public function uriProvisionnement(string $secret, string $email): string
    {
        $otp = Otp::createFromSecret($secret, $this->horloge);
        $otp->setLabel(str_replace(':', '_', $email));   // le deux-points sépare émetteur et libellé dans l'URI
        $otp->setIssuer(self::EMETTEUR);

        return $otp->getProvisioningUri();
    }

    public function verifier(string $secret, string $code): ?int
    {
        $code = str_replace(' ', '', $code);
        if (1 !== preg_match('/^[0-9]{'.self::CHIFFRES.'}$/', $code)) {
            return null;
        }

        $maintenant = $this->horloge->now()->getTimestamp();
        $otp = Otp::createFromSecret($secret, $this->horloge);
        $trouve = null;
        // Les trois pas sont toujours évalués : le temps de réponse ne dépend pas du pas qui correspond.
        for ($decalage = -self::TOLERANCE; $decalage <= self::TOLERANCE; ++$decalage) {
            $instant = $maintenant + $decalage * self::PAS;
            if ($instant >= 0 && hash_equals($otp->at($instant), $code)) {
                $trouve = intdiv($instant, self::PAS);
            }
        }

        return $trouve;
    }
}
