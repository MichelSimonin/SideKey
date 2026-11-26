<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;
use App\Service\Audit\JournalAudit;
use App\Service\Catalogue\CatalogueCache;

/**
 * Gestion des plateformes (US-50, F-09). Le code (STEAM, PSN…) est choisi à la création et ne change plus : seul le libellé
 * se modifie. Une plateforme utilisée par une offre ne se supprime pas (409, clé étrangère RESTRICT). Mêmes garde-fous que
 * GestionEditeurs : revalidation, audit, invalidation du cache (le libellé figure sur les fiches et dans les filtres).
 */
final class GestionPlateformes
{
    public const CODE = '/^[A-Z0-9_]{1,12}$/';
    public const LIBELLE_MAX = 60;

    public function __construct(
        private readonly PlateformesAdmin $plateformes,
        private readonly CatalogueCache $cache,
        private readonly JournalAudit $audit,
    ) {
    }

    /** @return list<PlateformeAdmin> */
    public function lister(): array
    {
        return $this->plateformes->lister();
    }

    public function trouver(string $id): PlateformeAdmin
    {
        return $this->plateformes->trouver($id);
    }

    public function creer(string $code, string $libelle, string $acteurId, ?string $ip): PlateformeAdmin
    {
        $erreurs = [];
        if (1 !== preg_match(self::CODE, $code)) {
            $erreurs['code'] = 'error.invalid_value';
        }
        $libelle = self::libelle($libelle, $erreurs);
        if ([] !== $erreurs) {
            throw CatalogueException::invalide($erreurs);
        }

        $plateforme = $this->plateformes->creer($code, $libelle);
        $this->audit->tracer(JournalAudit::CATALOGUE_CREATION, 'plateforme', $plateforme->id, $acteurId, $ip);
        $this->cache->invaliderListes();   // les valeurs des filtres changent

        return $plateforme;
    }

    public function modifier(string $id, string $libelle, string $acteurId, ?string $ip): PlateformeAdmin
    {
        $erreurs = [];
        $libelle = self::libelle($libelle, $erreurs);
        if ([] !== $erreurs) {
            throw CatalogueException::invalide($erreurs);
        }

        $plateforme = $this->plateformes->modifier($id, $libelle);
        $this->audit->tracer(JournalAudit::CATALOGUE_MODIFICATION, 'plateforme', $plateforme->id, $acteurId, $ip);
        foreach ($this->plateformes->slugsDesJeux($plateforme->id) as $slug) {
            $this->cache->invaliderJeu($slug);
        }
        $this->cache->invaliderListes();

        return $plateforme;
    }

    public function supprimer(string $id, string $acteurId, ?string $ip): void
    {
        $this->plateformes->supprimer($id);   // refusé (409) tant que des offres s'y rattachent
        $this->audit->tracer(JournalAudit::CATALOGUE_SUPPRESSION, 'plateforme', Identifiant::exiger($id), $acteurId, $ip);
        $this->cache->invaliderListes();
    }

    /** @param array<string, string> $erreurs */
    private static function libelle(string $libelle, array &$erreurs): string
    {
        $libelle = trim($libelle);
        if ('' === $libelle || mb_strlen($libelle) > self::LIBELLE_MAX || 1 === preg_match('/[<>\p{C}]/u', $libelle)) {
            $erreurs['libelle'] = 'error.invalid_value';
        }

        return $libelle;
    }
}
