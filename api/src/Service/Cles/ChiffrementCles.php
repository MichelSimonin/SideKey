<?php

declare(strict_types=1);

namespace App\Service\Cles;

/**
 * Protection des clés de licence au repos (ADR-0004, CDC §9.4). Implémentation :
 * Infrastructure/Crypto/ChiffreurClesAesGcm.
 *
 *  - chiffrer() / dechiffrer() : AES-256-GCM, IV aléatoire, chiffré lié à son offre (données associées) ;
 *  - empreinte() : HMAC-SHA256 de la clé normalisée, pour la contrainte UNIQUE (doublons).
 *
 * Seul LicenceService::reveler() appelle dechiffrer().
 */
interface ChiffrementCles
{
    /** @return string chiffré binaire (colonne cle_chiffree, BYTEA) */
    public function chiffrer(CleNormalisee $cle, string $offreId): string;

    /** @throws CleIllisibleException chiffré altéré, mauvaise offre ou mauvaise clé maître */
    public function dechiffrer(string $chiffre, string $offreId): string;

    /** @return string 32 octets (colonne empreinte, BYTEA UNIQUE) */
    public function empreinte(CleNormalisee $cle): string;
}
