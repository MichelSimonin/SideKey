<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\EmailDejaUtiliseException;
use App\Exception\MotDePasseInvalideException;
use App\Exception\TropDeTentativesException;
use App\Security\Utilisateur;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Création d'un compte client (US-20). Le DTO de la couche présentation valide déjà les champs ;
 * le service rejoue les règles qui protègent le compte (défense en profondeur) avant d'écrire.
 *
 * Le rôle est toujours CLIENT : le client ne peut ni choisir un rôle ni fixer un statut (mass assignment).
 * L'e-mail n'est pas vérifié à la création : l'envoi du message de vérification relève du ticket des e-mails.
 */
final class InscriptionService
{
    public const LANGUES = ['fr', 'en'];

    /** rl:register:{ip} : 5 créations de compte par heure et par adresse. */
    public const TENTATIVES_MAX = 5;
    public const FENETRE_SECONDES = 3600;

    public function __construct(
        private readonly ComptesUtilisateurs $comptes,
        private readonly LimiteurDebit $limiteur,
        private readonly PasswordHasherFactoryInterface $hashers,
        private readonly PolitiqueMotDePasse $politique,
    ) {
    }

    /**
     * @throws TropDeTentativesException   trop de créations depuis cette adresse (429)
     * @throws MotDePasseInvalideException mot de passe hors politique (422)
     * @throws EmailDejaUtiliseException   e-mail déjà pris (409)
     */
    public function inscrire(string $email, string $motDePasse, string $prenom, string $nom, string $langue, string $ip): Compte
    {
        $this->limiteur->enregistrerTentative('rl:register:'.AuthentificationService::adresseValide($ip), self::TENTATIVES_MAX, self::FENETRE_SECONDES);

        $email = AuthentificationService::normaliserEmail($email);
        $prenom = trim($prenom);
        $nom = trim($nom);

        if (!$this->politique->estConforme($motDePasse, $email, $prenom, $nom)) {
            throw new MotDePasseInvalideException();
        }

        $hash = $this->hashers->getPasswordHasher(Utilisateur::class)->hash($motDePasse);
        $id = $this->comptes->creerClient($email, $hash, $prenom, $nom, \in_array($langue, self::LANGUES, true) ? $langue : 'fr');

        $compte = $this->comptes->parId($id);
        if (null === $compte) {
            throw new \LogicException('Le compte vient d\'être créé : il doit exister.');
        }

        return $compte;
    }
}
