<?php

declare(strict_types=1);

namespace App\Service\Catalogue\Gestion;

use App\Exception\CatalogueException;
use App\Service\Audit\JournalAudit;
use App\Service\Catalogue\CatalogueCache;

/**
 * Gestion des éditeurs (US-50, F-09). L'autorisation (permission « gérer le catalogue », A2F) est vérifiée par la couche
 * présentation (#[IsGranted]) ; ce service revalide les entrées, écrit, trace dans le journal d'audit et invalide le cache
 * du catalogue : le nom d'un éditeur figure sur les fiches des jeux, un renommage doit y apparaître sans attendre le TTL.
 */
final class GestionEditeurs
{
    public const NOM_MAX = 120;

    public function __construct(
        private readonly EditeursAdmin $editeurs,
        private readonly CatalogueCache $cache,
        private readonly JournalAudit $audit,
    ) {
    }

    /** @return list<EditeurAdmin> */
    public function lister(): array
    {
        return $this->editeurs->lister();
    }

    public function trouver(string $id): EditeurAdmin
    {
        return $this->editeurs->trouver($id);
    }

    public function creer(string $nom, string $acteurId, ?string $ip): EditeurAdmin
    {
        $editeur = $this->editeurs->creer(self::nom($nom));
        $this->audit->tracer(JournalAudit::CATALOGUE_CREATION, 'editeur', $editeur->id, $acteurId, $ip);

        return $editeur;
    }

    public function modifier(string $id, string $nom, string $acteurId, ?string $ip): EditeurAdmin
    {
        $nom = self::nom($nom);
        $editeur = $this->editeurs->modifier($id, $nom);
        $this->audit->tracer(JournalAudit::CATALOGUE_MODIFICATION, 'editeur', $editeur->id, $acteurId, $ip);
        $this->invalider($this->editeurs->slugsDesJeux($editeur->id));

        return $editeur;
    }

    public function supprimer(string $id, string $acteurId, ?string $ip): void
    {
        $this->editeurs->supprimer($id);   // refusé (409) tant que des jeux s'y rattachent
        $this->audit->tracer(JournalAudit::CATALOGUE_SUPPRESSION, 'editeur', Identifiant::exiger($id), $acteurId, $ip);
    }

    /** @param list<string> $slugs */
    private function invalider(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->cache->invaliderJeu($slug);
        }
        $this->cache->invaliderListes();
    }

    private static function nom(string $nom): string
    {
        $nom = trim($nom);
        if ('' === $nom || mb_strlen($nom) > self::NOM_MAX || 1 === preg_match('/[<>\p{C}]/u', $nom)) {
            throw CatalogueException::invalide(['nom' => 'error.invalid_value']);
        }

        return $nom;
    }
}
