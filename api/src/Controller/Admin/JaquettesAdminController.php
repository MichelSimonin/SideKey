<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Dto\Response\CatalogueAdminResponse;
use App\Exception\JaquetteInvalideException;
use App\Security\Permission;
use App\Security\Utilisateur;
use App\Service\Catalogue\Gestion\GestionJaquettes;
use App\Service\Catalogue\Gestion\ValidationJaquette;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office : jaquette d'un jeu (US-50, upload sécurisé). Couche présentation : fichier présent et de taille bornée avant
 * toute lecture ; le type (contenu réel, pas l'extension ni le Content-Type envoyés), les dimensions et le nom régénéré
 * relèvent de GestionJaquettes.
 */
#[Route('/api/admin/jeux/{id}/jaquette', name: 'api_admin_jaquette_', requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'])]
#[IsGranted(Permission::GererCatalogue->value)]
final class JaquettesAdminController
{
    public function __construct(private readonly GestionJaquettes $jaquettes)
    {
    }

    #[Route('', name: 'definir', methods: ['POST'])]
    public function definir(Request $requete, string $id, #[CurrentUser] Utilisateur $utilisateur): JsonResponse
    {
        $fichier = $requete->files->get('jaquette');
        $trop = ['%max%' => intdiv(ValidationJaquette::OCTETS_MAX, 1024)];
        if (!$fichier instanceof UploadedFile) {
            throw new JaquetteInvalideException(JaquetteInvalideException::ABSENTE);
        }
        if (\in_array($fichier->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw new JaquetteInvalideException(JaquetteInvalideException::TROP_LOURDE, $trop);
        }
        if (!$fichier->isValid()) {
            throw new JaquetteInvalideException(JaquetteInvalideException::ABSENTE);
        }
        if ($fichier->getSize() > ValidationJaquette::OCTETS_MAX) {
            throw new JaquetteInvalideException(JaquetteInvalideException::TROP_LOURDE, $trop);
        }

        $jeu = $this->jaquettes->definir($id, (string) file_get_contents($fichier->getPathname()), $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::json(CatalogueAdminResponse::jeu($jeu));
    }

    #[Route('', name: 'retirer', methods: ['DELETE'])]
    public function retirer(Request $requete, string $id, #[CurrentUser] Utilisateur $utilisateur): Response
    {
        $this->jaquettes->retirer($id, $utilisateur->compte->id, $requete->getClientIp());

        return ReponseAdmin::vide();
    }
}
