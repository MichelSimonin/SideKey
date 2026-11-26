<?php

declare(strict_types=1);

namespace App\Service\A2f;

/**
 * Accès aux données de l'A2F (couche données). Implémentation : Repository/A2fRepository (DBAL, SQL paramétré).
 * Seuls des chiffrés et des hachages traversent cette interface : jamais un secret ni un code de secours en clair.
 */
interface ComptesA2f
{
    /** Secret TOTP chiffré du compte (en attente de confirmation ou actif), null s'il n'y en a pas. */
    public function secretChiffre(string $utilisateurId): ?string;

    /**
     * Enregistre un secret en attente de confirmation (remplace le précédent). Sans effet si l'A2F est déjà active :
     * un secret actif ne s'écrase jamais par ce chemin.
     *
     * @return bool true si le secret a été enregistré
     */
    public function enregistrerSecretEnAttente(string $utilisateurId, string $secretChiffre): bool;

    /**
     * Active l'A2F et remplace les codes de secours par $hachages, **tout ou rien** (une transaction) : les anciens codes
     * sont supprimés, les nouveaux insérés, a2f_active passe à vrai.
     *
     * @param list<string> $hachages un haché par code de secours
     *
     * @return bool false si l'A2F était déjà active ou s'il n'y avait aucun secret en attente
     */
    public function activer(string $utilisateurId, array $hachages): bool;

    /** @return list<array{id: string, hache: string}> codes de secours non utilisés */
    public function codesSecoursDisponibles(string $utilisateurId): array;

    /**
     * Consomme un code de secours : l'UPDATE ne réussit qu'une fois (utilise_le IS NULL), même sous concurrence.
     *
     * @return bool true si ce code vient d'être consommé
     */
    public function consommerCodeSecours(string $codeId): bool;
}
