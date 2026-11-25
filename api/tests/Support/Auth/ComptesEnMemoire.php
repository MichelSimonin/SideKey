<?php

declare(strict_types=1);

namespace App\Tests\Support\Auth;

use App\Exception\EmailDejaUtiliseException;
use App\Service\Auth\Compte;
use App\Service\Auth\ComptesUtilisateurs;

/** Doublure du repository des comptes pour les tests unitaires des services. */
final class ComptesEnMemoire implements ComptesUtilisateurs
{
    /** @var array<string, Compte> */
    public array $comptes = [];

    /** @var list<array{id: string, nouveauHash: ?string}> */
    public array $connexions = [];

    public function ajouter(Compte $compte): Compte
    {
        return $this->comptes[$compte->id] = $compte;
    }

    public function parEmail(string $email): ?Compte
    {
        foreach ($this->comptes as $compte) {
            if (strtolower($compte->email) === strtolower($email)) {
                return $compte;
            }
        }

        return null;
    }

    public function parId(string $id): ?Compte
    {
        return $this->comptes[$id] ?? null;
    }

    public function creerClient(string $email, string $motDePasseHash, string $prenom, string $nom, string $langue): string
    {
        if (null !== $this->parEmail($email)) {
            throw new EmailDejaUtiliseException();
        }
        $id = FabriqueCompte::uuid(\count($this->comptes) + 1);
        $this->comptes[$id] = new Compte($id, $email, $motDePasseHash, $prenom, $nom, $langue, ['CLIENT'], false, true, false);

        return $id;
    }

    public function enregistrerConnexion(string $id, ?string $nouveauHash = null): void
    {
        $this->connexions[] = ['id' => $id, 'nouveauHash' => $nouveauHash];
        if (null !== $nouveauHash && isset($this->comptes[$id])) {
            $c = $this->comptes[$id];
            $this->comptes[$id] = new Compte($c->id, $c->email, $nouveauHash, $c->prenom, $c->nom, $c->langue, $c->roles, $c->a2fActive, $c->actif, $c->emailVerifie);
        }
    }
}
