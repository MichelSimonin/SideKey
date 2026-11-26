<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;
use App\Exception\ConflitOffreException;
use App\Service\Audit\JournalAudit;
use App\Service\Catalogue\CatalogueCache;

/**
 * Gestion des offres (US-51, BX-14, F-09) : prix et seuil d'alerte d'une offre jeu × plateforme, activation.
 *
 * Le prix est validé ici (décimal strictement positif, deux décimales au plus) et par la base (ck_offre_prix). Les prix déjà
 * figés dans une commande ne changent pas (RG-05). Deux gestionnaires qui éditent la même offre : le second est refusé en 409
 * (verrouillage optimiste par version) au lieu d'écraser le travail du premier. Le cache du catalogue est invalidé : le prix
 * minimum d'une liste et le prix d'une fiche viennent de cette table.
 */
final class GestionOffres
{
    public const PRIX = '/^\d{1,5}(\.\d{1,2})?$/';
    public const SEUIL_MAX = 32_767;   // smallint

    public function __construct(
        private readonly OffresAdmin $offres,
        private readonly CatalogueCache $cache,
        private readonly JournalAudit $audit,
    ) {
    }

    /**
     * @throws CatalogueException jeu ou plateforme introuvable (404), offre active déjà existante (409), valeur invalide (422)
     */
    public function creer(string $jeuId, string $plateformeId, string $prix, int $seuilAlerte, string $acteurId, ?string $ip): OffreAdmin
    {
        self::verifier($prix, $seuilAlerte);

        ['offre' => $offre, 'jeuSlug' => $slug] = $this->offres->creer($jeuId, $plateformeId, $prix, $seuilAlerte);

        $this->audit->tracer(JournalAudit::CATALOGUE_CREATION, 'offre', $offre->id, $acteurId, $ip);
        $this->invalider($slug);

        return $offre;
    }

    /**
     * @throws ConflitOffreException un collègue a modifié l'offre depuis son chargement (409)
     * @throws CatalogueException    introuvable (404), réactivation impossible (409), valeur invalide (422)
     */
    public function modifier(string $offreId, string $prix, int $seuilAlerte, bool $actif, int $versionAttendue, string $acteurId, ?string $ip): OffreAdmin
    {
        self::verifier($prix, $seuilAlerte);
        if ($versionAttendue < 1) {
            throw CatalogueException::invalide(['version' => 'error.invalid_value']);
        }

        ['offre' => $offre, 'jeuSlug' => $slug] = $this->offres->modifier($offreId, $prix, $seuilAlerte, $actif, $versionAttendue);

        $this->audit->tracer(JournalAudit::CATALOGUE_MODIFICATION, 'offre', $offre->id, $acteurId, $ip);
        $this->invalider($slug);

        return $offre;
    }

    private static function verifier(string $prix, int $seuilAlerte): void
    {
        $erreurs = [];
        if (1 !== preg_match(self::PRIX, $prix) || (float) $prix <= 0.0) {
            $erreurs['prix'] = 'error.invalid_value';
        }
        if ($seuilAlerte < 0 || $seuilAlerte > self::SEUIL_MAX) {
            $erreurs['seuilAlerte'] = 'error.invalid_value';
        }
        if ([] !== $erreurs) {
            throw CatalogueException::invalide($erreurs);
        }
    }

    private function invalider(string $slug): void
    {
        $this->cache->invaliderJeu($slug);
        $this->cache->invaliderListes();
    }
}
