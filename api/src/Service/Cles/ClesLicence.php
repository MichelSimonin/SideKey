<?php

declare(strict_types=1);

namespace App\Service\Cles;

/**
 * Accès aux clés de licence et aux imports (couche d'accès aux données). Implémentation :
 * Repository/ClesLicenceRepository (DBAL, SQL paramétré, compte sk_app).
 *
 * Aucune valeur en clair ne traverse cette interface : seulement des chiffrés, des empreintes et des suffixes.
 */
interface ClesLicence
{
    /** @return string|null slug du jeu de l'offre si elle existe et est en vente, sinon null */
    public function offreActive(string $offreId): ?string;

    /** Crée le rapport d'import (statut EN_COURS) et renvoie son identifiant. */
    public function ouvrirImport(string $nomFichier, string $utilisateurId): string;

    /**
     * Insère un lot dans une transaction ; une empreinte déjà connue est ignorée (doublon), pas une erreur.
     *
     * @param list<CleAImporter> $cles
     *
     * @return list<int> numéros de ligne réellement insérés
     */
    public function insererLot(string $offreId, string $importId, array $cles): array;

    public function cloreImport(RapportImport $rapport): void;

    /** Clé d'une commande appartenant à cet utilisateur, sinon null (anti-IDOR dès la requête SQL). */
    public function cleDuProprietaire(string $cleId, string $utilisateurId): ?CleRevelable;

    /** Date de première révélation (RG-20 : une clé révélée n'est plus remboursable) ; sans effet ensuite. */
    public function marquerRevelee(string $cleId): \DateTimeImmutable;
}
