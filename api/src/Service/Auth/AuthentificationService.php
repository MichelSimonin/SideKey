<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\IdentifiantsInvalidesException;
use App\Exception\TropDeTentativesException;
use App\Security\Utilisateur;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * Connexion par e-mail et mot de passe (US-21, CDC §11.4).
 *
 * Fail Securely et anti-énumération : e-mail inconnu, compte désactivé et mauvais mot de passe produisent
 * la même exception et un coût de calcul comparable (un hachage est toujours vérifié).
 */
final class AuthentificationService
{
    /** rl:login:{ip} (CDC §9.8) : 10 tentatives par fenêtre de 15 minutes. */
    public const TENTATIVES_MAX = 10;
    public const FENETRE_SECONDES = 900;

    private ?string $hashFactice = null;

    public function __construct(
        private readonly ComptesUtilisateurs $comptes,
        private readonly LimiteurDebit $limiteur,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly SessionService $sessions,
    ) {
    }

    /**
     * @param string $ip adresse du client, pour la limitation de débit
     *
     * @throws TropDeTentativesException   trop de tentatives depuis cette adresse (429)
     * @throws IdentifiantsInvalidesException identifiants incorrects, compte inconnu ou désactivé (401)
     */
    public function connecter(string $email, string $motDePasse, string $ip): ResultatConnexion
    {
        $this->limiteur->enregistrerTentative('rl:login:'.self::adresseValide($ip), self::TENTATIVES_MAX, self::FENETRE_SECONDES);

        $hasher = $this->hashers->getPasswordHasher(Utilisateur::class);
        $compte = $this->comptes->parEmail(self::normaliserEmail($email));

        // Toujours un hachage à vérifier, même sans compte : le temps de réponse ne révèle rien.
        $hashReference = $compte?->motDePasseHash ?? $this->hashFactice($hasher);
        $mdpAdmissible = mb_strlen($motDePasse) <= PolitiqueMotDePasse::LONGUEUR_MAX;
        $valide = $mdpAdmissible && $hasher->verify($hashReference, $motDePasse);

        if (null === $compte || !$compte->actif || !$valide) {
            throw new IdentifiantsInvalidesException();
        }

        // Le hachage évolue avec la configuration (bcrypt du jeu d'essai, coût argon2id relevé…).
        $this->comptes->enregistrerConnexion($compte->id, $hasher->needsRehash($compte->motDePasseHash) ? $hasher->hash($motDePasse) : null);

        if ($compte->a2fActive) {
            // 2e étape (F-04) : ni jeton d'accès ni refresh avant la vérification du code TOTP.
            return ResultatConnexion::secondeEtape($this->sessions->emettreJetonIntermediaire($compte), SessionService::DUREE_INTERMEDIAIRE);
        }

        return ResultatConnexion::connecte($this->sessions->ouvrir($compte));
    }

    public static function normaliserEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** L'adresse entre dans une clé Redis : une valeur qui n'est pas une IP ne doit jamais y figurer. */
    public static function adresseValide(string $ip): string
    {
        return false !== filter_var($ip, \FILTER_VALIDATE_IP) ? $ip : 'inconnue';
    }

    private function hashFactice(PasswordHasherInterface $hasher): string
    {
        return $this->hashFactice ??= $hasher->hash('mot-de-passe-factice-pour-egaliser-le-temps');
    }
}
