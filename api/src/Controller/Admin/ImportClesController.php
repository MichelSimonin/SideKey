<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Response\RapportImportResponse;
use App\Exception\ImportClesInvalideException;
use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Cles\ImportClesService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office : import de clés de licence en CSV pour une offre (US-52, écran E13). Couche présentation.
 *
 * Contrôles de cette couche : permission « gérer le catalogue » (GESTIONNAIRE, ADMIN), identifiant d'offre au
 * format UUID, fichier présent, de 512 Kio au plus et d'extension .csv ou .txt. Le contenu est validé ligne à
 * ligne par ImportClesService. L'A2F obligatoire du back-office s'ajoutera devant cette route avec F-04.
 */
final class ImportClesController
{
    private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
    public const TAILLE_MAX = 512 * 1024;
    private const EXTENSIONS = ['csv', 'txt'];

    public function __construct(private readonly ImportClesService $imports)
    {
    }

    #[Route('/api/admin/offres/{offreId}/cles', name: 'api_admin_import_cles', requirements: ['offreId' => self::UUID], methods: ['POST'])]
    #[IsGranted(Permission::GererCatalogue->value)]
    public function __invoke(Request $requete, string $offreId, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $fichier = $requete->files->get('fichier');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            throw new ImportClesInvalideException(
                $fichier instanceof UploadedFile && \in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                    ? 'error.import_fichier_trop_gros' : 'error.import_fichier_absent',
                ['%maximum%' => self::TAILLE_MAX / 1024],
            );
        }
        if ($fichier->getSize() > self::TAILLE_MAX) {
            throw new ImportClesInvalideException('error.import_fichier_trop_gros', ['%maximum%' => self::TAILLE_MAX / 1024]);
        }
        if (!\in_array(strtolower($fichier->getClientOriginalExtension()), self::EXTENSIONS, true)) {
            throw new ImportClesInvalideException('error.import_fichier_type');
        }

        $rapport = $this->imports->importer(
            strtolower($offreId),
            $fichier->getClientOriginalName(),
            (string) file_get_contents($fichier->getPathname()),
            $utilisateur->compte->id,
            $requete->getClientIp(),
        );

        $reponse = new JsonResponse(RapportImportResponse::depuis($rapport), Response::HTTP_CREATED);
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }
}
