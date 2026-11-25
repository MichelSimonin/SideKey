<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\Response\SessionResponse;
use App\Dto\Response\UtilisateurResponse;
use App\Service\Auth\ResultatConnexion;
use App\Service\Auth\Session;
use App\Tests\Support\Auth\FabriqueCompte;
use PHPUnit\Framework\TestCase;

/** Limited View : un DTO de sortie ne contient que les champs prévus, jamais un secret. */
final class UtilisateurResponseTest extends TestCase
{
    public function testAucunSecretDansLaReponse(): void
    {
        $compte = FabriqueCompte::creer(1);
        $json = json_encode(UtilisateurResponse::depuis($compte), \JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($compte->motDePasseHash, $json);
        self::assertStringNotContainsString('argon2', $json);
        foreach (['motDePasse', 'hash', 'totp', 'secret', 'code_secours', 'password'] as $interdit) {
            self::assertStringNotContainsStringIgnoringCase($interdit, $json);
        }
    }

    public function testChampsExposes(): void
    {
        self::assertSame(
            ['id', 'email', 'prenom', 'nom', 'langue', 'roles', 'permissions', 'a2fActive', 'emailVerifie'],
            array_keys(UtilisateurResponse::depuis(FabriqueCompte::creer(1))),
        );
    }

    public function testPermissionsDeduitesDeLaMatrice(): void
    {
        self::assertSame(['commander', 'deposer_avis'], UtilisateurResponse::depuis(FabriqueCompte::creer(1, ['CLIENT']))['permissions']);
        self::assertSame(['moderer'], UtilisateurResponse::depuis(FabriqueCompte::creer(1, ['MODERATEUR']))['permissions']);
        self::assertSame(
            ['gerer_catalogue', 'moderer', 'consulter_commandes', 'valider_commande_suspecte', 'gerer_roles_et_audit'],
            UtilisateurResponse::depuis(FabriqueCompte::creer(1, ['ADMIN']))['permissions'],
        );
        self::assertSame([], UtilisateurResponse::depuis(FabriqueCompte::creer(1, []))['permissions']);
    }

    public function testLeJetonDeRafraichissementNApparaitJamaisDansLeCorps(): void
    {
        $session = new Session(FabriqueCompte::creer(1), 'jeton.acces.jwt', 900, str_repeat('ab', 32));

        $corps = SessionResponse::depuis($session);

        self::assertSame('connecte', $corps['etape']);
        self::assertSame('Bearer', $corps['typeJeton']);
        self::assertSame(900, $corps['expireDans']);
        self::assertStringNotContainsString(str_repeat('ab', 32), json_encode($corps, \JSON_THROW_ON_ERROR), 'le refresh voyage uniquement par cookie HttpOnly');
    }

    public function testLEtapeA2fNeDonneNiAccesNiUtilisateur(): void
    {
        $corps = SessionResponse::etapeA2f(ResultatConnexion::secondeEtape('jeton.intermediaire', 300));

        self::assertSame(['etape', 'jetonIntermediaire', 'expireDans'], array_keys($corps));
        self::assertSame('a2f', $corps['etape']);
    }
}
