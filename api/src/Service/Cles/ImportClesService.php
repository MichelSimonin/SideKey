<?php

declare(strict_types=1);

namespace App\Service\Cles;

use App\Exception\ImportClesInvalideException;
use App\Exception\OffreIndisponibleException;
use App\Service\Audit\JournalAudit;
use App\Service\Catalogue\CatalogueCache;

/**
 * Import de clés de licence en CSV (US-52, BX-11, BX-13). Couche métier.
 *
 * Fichier : une clé par ligne, dans la première colonne ; une ligne d'en-tête « cle » ou « key » est
 * ignorée, comme les lignes vides. Synchrone jusqu'à 1 000 clés en v1 (au-delà : P-13, asynchrone).
 *
 * Chaque ligne est validée, normalisée, chiffrée et prise en empreinte ici ; la base ne reçoit que des
 * chiffrés. Les lots de 500 lignes sont insérés chacun dans sa transaction (CDC §10.3) et les doublons
 * sont rejetés ligne à ligne : ceux du fichier ici, ceux du stock par la contrainte UNIQUE.
 */
final class ImportClesService
{
    public const LIGNES_MAX = 1000;
    public const TAILLE_LOT = 500;
    private const ENTETES = ['cle', 'clé', 'cles', 'key', 'keys', 'licence', 'license', 'cle_licence', 'license_key'];

    public function __construct(
        private readonly ClesLicence $cles,
        private readonly ChiffrementCles $chiffrement,
        private readonly JournalAudit $journal,
        private readonly CatalogueCache $cache,
    ) {
    }

    /**
     * @param string $contenu contenu brut du fichier (UTF-8)
     */
    public function importer(string $offreId, string $nomFichier, #[\SensitiveParameter] string $contenu, string $utilisateurId, ?string $adresseIp): RapportImport
    {
        $slug = $this->cles->offreActive($offreId);
        if (null === $slug) {
            throw new OffreIndisponibleException();
        }
        $lignes = self::lignes($contenu);

        $rapport = new RapportImport($this->cles->ouvrirImport(self::nomSur($nomFichier), $utilisateurId), self::nomSur($nomFichier));
        $succes = false;
        try {
            $vues = [];
            $lot = [];
            foreach ($lignes as $numero => $brute) {
                $rapport->compterLigne();
                $cle = CleNormalisee::depuis(self::premiereColonne($brute));
                if (null === $cle) {
                    $rapport->refuser($numero, RapportImport::MOTIF_FORMAT);
                    continue;
                }
                $empreinte = $this->chiffrement->empreinte($cle);
                if (isset($vues[$empreinte])) {
                    $rapport->refuser($numero, RapportImport::MOTIF_DOUBLON_FICHIER);
                    continue;
                }
                $vues[$empreinte] = true;
                $lot[] = new CleAImporter($numero, $this->chiffrement->chiffrer($cle, $offreId), $empreinte, $cle->suffixe());
                if (\count($lot) === self::TAILLE_LOT) {
                    $this->insererLot($offreId, $rapport, $lot);
                    $lot = [];
                }
            }
            $this->insererLot($offreId, $rapport, $lot);
            $succes = true;
        } finally {
            // Même en cas d'échec, le rapport dit ce qui a été inséré (les lots déjà validés restent en base)
            $rapport->terminer($succes);
            $this->cles->cloreImport($rapport);
            if ($rapport->nbAcceptees() > 0) {
                // Le stock disponible figure dans les fiches et les listes du catalogue (F-01)
                $this->cache->invaliderJeu($slug);
                $this->cache->invaliderListes();
            }
        }
        $this->journal->tracer(JournalAudit::IMPORT_CLES, 'import_cles', $rapport->id, $utilisateurId, $adresseIp);

        return $rapport;
    }

    /** @param list<CleAImporter> $lot */
    private function insererLot(string $offreId, RapportImport $rapport, array $lot): void
    {
        if ([] === $lot) {
            return;
        }
        $inserees = array_flip($this->cles->insererLot($offreId, $rapport->id, $lot));
        $rapport->accepter(\count($inserees));
        foreach ($lot as $cle) {
            if (!isset($inserees[$cle->ligne])) {
                $rapport->refuser($cle->ligne, RapportImport::MOTIF_DOUBLON_STOCK);
            }
        }
    }

    /**
     * Lignes utiles, indexées par leur numéro dans le fichier (1 = première ligne).
     *
     * @return array<int, string>
     */
    private static function lignes(#[\SensitiveParameter] string $contenu): array
    {
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);   // BOM UTF-8 d'Excel
        }
        if (!mb_check_encoding($contenu, 'UTF-8')) {
            throw new ImportClesInvalideException('error.import_encodage');
        }
        $lignes = [];
        foreach (preg_split('/\r\n|\n|\r/', $contenu) ?: [] as $index => $ligne) {
            if ('' === trim($ligne)) {
                continue;
            }
            if ([] === $lignes && \in_array(mb_strtolower(trim(self::premiereColonne($ligne))), self::ENTETES, true)) {
                continue;   // en-tête
            }
            $lignes[$index + 1] = $ligne;
        }
        if ([] === $lignes) {
            throw new ImportClesInvalideException('error.import_vide');
        }
        if (\count($lignes) > self::LIGNES_MAX) {
            throw new ImportClesInvalideException('error.import_trop_de_lignes', ['%maximum%' => self::LIGNES_MAX]);
        }

        return $lignes;
    }

    private static function premiereColonne(#[\SensitiveParameter] string $ligne): string
    {
        $colonnes = str_getcsv($ligne, str_contains($ligne, ';') ? ';' : ',', '"', '');

        return (string) ($colonnes[0] ?? '');
    }

    /** Nom de fichier affichable : sans chemin ni caractère de contrôle, 255 caractères au plus. */
    private static function nomSur(string $nom): string
    {
        $nom = basename(str_replace('\\', '/', $nom));
        $nom = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $nom);
        $nom = mb_substr('' === trim($nom) ? 'import.csv' : $nom, 0, 255);

        return $nom;
    }
}
