<?php

declare(strict_types=1);

namespace App\Service\Paiement;

use App\Exception\PaiementInvalideException;

/**
 * Carte de paiement saisie par le client. Objet à vie courte : il traverse le service jusqu'au prestataire et n'est jamais
 * conservé. Seuls les quatre derniers chiffres sortent de cette classe (derniersChiffres) pour être enregistrés.
 *
 * Ce qui le protège d'une fuite par inadvertance : champs privés, aucun accesseur de l'expiration ni du cryptogramme,
 * var_dump et print_r n'affichent que les quatre derniers chiffres, la sérialisation est interdite (journaux, cache,
 * session), et les paramètres de constructeur sont marqués #[\SensitiveParameter] (absents des traces d'exception).
 */
final class CartePaiement
{
    private function __construct(
        private readonly string $numero,
        private readonly string $derniers,
    ) {
    }

    /**
     * Les contrôles de format ne remplacent pas le prestataire : ils renvoient tout de suite une erreur par champ, sans
     * appeler le prestataire ni compter un échec de paiement, pour une faute de frappe.
     *
     * @throws PaiementInvalideException numéro (longueur, clé de Luhn), expiration (format, date dépassée) ou cryptogramme
     */
    public static function depuis(
        #[\SensitiveParameter] string $numero,
        #[\SensitiveParameter] string $expiration,
        #[\SensitiveParameter] string $cryptogramme,
        \DateTimeImmutable $maintenant,
    ): self {
        $erreurs = [];

        $chiffres = preg_replace('/[ -]/', '', $numero) ?? '';
        if (1 !== preg_match('/^\d{13,19}$/', $chiffres) || !self::luhn($chiffres)) {
            $erreurs['numeroCarte'] = 'error.paiement_carte_numero';
        }
        if (1 !== preg_match('/^(0[1-9]|1[0-2])\/(\d{2})$/', $expiration, $m) || self::dateDepassee((int) $m[1], 2000 + (int) $m[2], $maintenant)) {
            $erreurs['expiration'] = 'error.paiement_carte_expiration';
        }
        if (1 !== preg_match('/^\d{3,4}$/', $cryptogramme)) {
            $erreurs['cryptogramme'] = 'error.paiement_carte_cryptogramme';
        }
        if ([] !== $erreurs) {
            throw new PaiementInvalideException($erreurs);
        }

        return new self($chiffres, substr($chiffres, -4));
    }

    /** Numéro sans espaces, pour le prestataire uniquement. */
    public function numero(): string
    {
        return $this->numero;
    }

    public function derniersChiffres(): string
    {
        return $this->derniers;
    }

    /** @return array{derniersChiffres: string} */
    public function __debugInfo(): array
    {
        return ['derniersChiffres' => $this->derniers];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Une carte de paiement ne se sérialise pas.');
    }

    private static function luhn(string $chiffres): bool
    {
        $somme = 0;
        $double = false;
        for ($i = \strlen($chiffres) - 1; $i >= 0; --$i) {
            $c = (int) $chiffres[$i];
            if ($double) {
                $c *= 2;
                $c -= $c > 9 ? 9 : 0;
            }
            $somme += $c;
            $double = !$double;
        }

        return 0 === $somme % 10;
    }

    /** Une carte vaut jusqu'à la fin du mois d'expiration. */
    private static function dateDepassee(int $mois, int $annee, \DateTimeImmutable $maintenant): bool
    {
        $fin = (new \DateTimeImmutable(\sprintf('%04d-%02d-01 00:00:00', $annee, $mois), new \DateTimeZone('UTC')))
            ->modify('last day of this month')->setTime(23, 59, 59);

        return $fin < $maintenant;
    }
}
