<?php

declare(strict_types=1);

namespace App\Service\Cles;

/**
 * Clé de licence validée et normalisée, telle qu'elle est chiffrée puis restituée à l'acheteur.
 *
 *  - valeur      : majuscules, espaces de bord retirés, tirets conservés (XXXXX-XXXXX-XXXXX) ;
 *  - canonique   : sans séparateur, base de l'empreinte HMAC : « abcde-12345 » et « ABCDE12345 »
 *                  sont la même clé, donc un doublon ;
 *  - suffixe     : 4 derniers caractères, seuls affichés au back-office (§9.4, BX-13).
 *
 * Format accepté : 8 à 64 caractères, lettres et chiffres séparés par des tirets simples. Tout le reste
 * (espaces internes, caractères de contrôle, guillemets, formules de tableur « =… ») est refusé.
 * La valeur n'apparaît jamais dans un message d'erreur, un journal ou une réponse d'import ; les paramètres
 * qui la portent sont #[SensitiveParameter] : masqués dans les traces d'exception.
 */
final class CleNormalisee
{
    private const FORMAT = '/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/';
    public const LONGUEUR_MIN = 8;
    public const LONGUEUR_MAX = 64;

    private function __construct(
        #[\SensitiveParameter] public readonly string $valeur,
        #[\SensitiveParameter] public readonly string $canonique,
    ) {
    }

    /** @return self|null null si la valeur n'est pas une clé de licence valide */
    public static function depuis(#[\SensitiveParameter] string $brute): ?self
    {
        $valeur = strtoupper(trim($brute, " \t"));
        $longueur = \strlen($valeur);
        if ($longueur < self::LONGUEUR_MIN || $longueur > self::LONGUEUR_MAX || 1 !== preg_match(self::FORMAT, $valeur)) {
            return null;
        }

        return new self($valeur, str_replace('-', '', $valeur));
    }

    public function suffixe(): string
    {
        return substr($this->canonique, -4);
    }

    /** Jamais de valeur en clair dans un var_dump, une trace d'exception ou un journal. */
    public function __debugInfo(): array
    {
        return ['suffixe' => $this->suffixe()];
    }
}
