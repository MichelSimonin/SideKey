<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Exception\EmailDejaUtiliseException;

/**
 * Accès aux comptes (couche données). Implémentation : Repository/UtilisateurRepository.
 * Aucune décision d'autorisation ici : le service appelant décide.
 */
interface ComptesUtilisateurs
{
    public function parEmail(string $email): ?Compte;

    /** Un identifiant qui n'est pas un UUID ne donne jamais lieu à une requête : résultat null. */
    public function parId(string $id): ?Compte;

    /**
     * Crée un compte CLIENT (utilisateur et rôle dans une seule instruction : tout ou rien).
     *
     * @return string identifiant du compte créé
     *
     * @throws EmailDejaUtiliseException un compte existe déjà avec cet e-mail (insensible à la casse)
     */
    public function creerClient(string $email, string $motDePasseHash, string $prenom, string $nom, string $langue): string;

    /** Enregistre la connexion ; $nouveauHash remplace l'ancien quand l'algorithme a évolué (ex. bcrypt → argon2id). */
    public function enregistrerConnexion(string $id, ?string $nouveauHash = null): void;
}
