<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Politique de mots de passe (CDC §11.4, B9 ; recommandations ANSSI et CNIL) :
 *  - 12 caractères au minimum, 128 au maximum (le plafond borne aussi le coût du hachage argon2id) ;
 *  - tous les caractères acceptés (espaces, accents, emojis : les phrases de passe sont encouragées) ;
 *  - aucune règle de composition imposée, aucune expiration forcée ;
 *  - refus des mots de passe triviaux et de ceux qui contiennent l'identité du titulaire.
 *
 * La liste est locale. Une vérification de compromission (Have I Been Pwned, k-anonymat) reste « À valider »
 * au CDC : elle ajouterait un appel sortant.
 */
final class PolitiqueMotDePasse
{
    public const LONGUEUR_MIN = 12;
    public const LONGUEUR_MAX = 128;

    /** Mots de passe de 12 caractères ou plus parmi les plus courants (forme normalisée : minuscules, sans espace). */
    private const COURANTS = [
        'password1234', 'password12345', 'password123456', 'motdepasse123', 'motdepasse1234', 'motdepasse12345',
        'azertyuiop12', 'azertyuiop123', 'azertyuiopqs', 'azertyuiopqsdf', 'qwertyuiop12', 'qwertyuiop123',
        'qwertyuiopas', '123456789012', '1234567890123', '12345678901234', '123456789abc', 'abcdefghijkl',
        'azertyazerty', 'qwertyqwerty', 'iloveyou1234', 'administrator', 'administrateur', 'sidekey12345',
        'sidekeysidekey', 'changemeplease', 'letmein12345', 'welcome12345', 'bienvenue1234', 'jetaimebeaucoup',
        'superman12345', 'football12345', 'azerty123456', 'qwerty123456',
    ];

    /** @return list<MotDePasseViolation> vide si le mot de passe est conforme */
    public function violations(string $motDePasse, string $email = '', string $prenom = '', string $nom = ''): array
    {
        $violations = [];
        $longueur = mb_strlen($motDePasse);
        if ($longueur < self::LONGUEUR_MIN) {
            $violations[] = MotDePasseViolation::TropCourt;
        }
        if ($longueur > self::LONGUEUR_MAX) {
            $violations[] = MotDePasseViolation::TropLong;
        }
        if ([] !== $violations) {
            return $violations;   // inutile d'analyser un mot de passe hors bornes
        }

        $normalise = self::normaliser($motDePasse);
        if (\in_array($normalise, self::COURANTS, true) || 1 === \count(array_unique(mb_str_split($normalise)))) {
            $violations[] = MotDePasseViolation::Courant;
        }

        foreach ($this->identites($email, $prenom, $nom) as $identite) {
            if (str_contains($normalise, $identite)) {
                $violations[] = MotDePasseViolation::ContientIdentifiant;
                break;
            }
        }

        return $violations;
    }

    public function estConforme(string $motDePasse, string $email = '', string $prenom = '', string $nom = ''): bool
    {
        return [] === $this->violations($motDePasse, $email, $prenom, $nom);
    }

    /**
     * Éléments d'identité (au moins 4 caractères, pour ne pas refuser un mot de passe à cause de « Léa »)
     * dont la présence rend le mot de passe devinable.
     *
     * @return list<string>
     */
    private function identites(string $email, string $prenom, string $nom): array
    {
        $parties = [mb_strstr($email, '@', true) ?: $email, $prenom, $nom];
        $identites = [];
        foreach ($parties as $partie) {
            $partie = self::normaliser($partie);
            if (mb_strlen($partie) >= 4) {
                $identites[] = $partie;
            }
        }

        return $identites;
    }

    private static function normaliser(string $valeur): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $valeur));
    }
}
