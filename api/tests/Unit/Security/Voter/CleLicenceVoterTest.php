<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\Voter;

use App\Security\Utilisateur;
use App\Security\Voter\CleLicenceVoter;
use App\Service\Auth\Compte;
use App\Service\Cles\CleRevelable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/** Anti-IDOR (CDC §9.4, §11.5) : propriétaire + CLIENT + commande PAYEE + clé VENDUE, sinon refus. */
final class CleLicenceVoterTest extends TestCase
{
    private const ACHETEUR = '11111111-2222-4333-8444-555555555555';
    private const AUTRE = '66666666-7777-4888-8999-000000000000';

    /** @return iterable<string, array{string, list<string>, string, string, int}> */
    public static function cas(): iterable
    {
        $oui = VoterInterface::ACCESS_GRANTED;
        $non = VoterInterface::ACCESS_DENIED;
        yield 'acheteur, commande payée' => [self::ACHETEUR, ['CLIENT'], 'PAYEE', 'VENDUE', $oui];
        yield 'autre client' => [self::AUTRE, ['CLIENT'], 'PAYEE', 'VENDUE', $non];
        yield 'commande à vérifier (anti-fraude)' => [self::ACHETEUR, ['CLIENT'], 'A_VERIFIER', 'RESERVEE', $non];
        yield 'commande en attente de paiement' => [self::ACHETEUR, ['CLIENT'], 'EN_ATTENTE_PAIEMENT', 'RESERVEE', $non];
        yield 'commande remboursée' => [self::ACHETEUR, ['CLIENT'], 'REMBOURSEE', 'VENDUE', $non];
        yield 'clé pas vendue malgré commande payée' => [self::ACHETEUR, ['CLIENT'], 'PAYEE', 'RESERVEE', $non];
        yield 'acheteur devenu sans rôle CLIENT' => [self::ACHETEUR, [], 'PAYEE', 'VENDUE', $non];
        yield 'administrateur (même propriétaire de la ligne)' => [self::ACHETEUR, ['ADMIN'], 'PAYEE', 'VENDUE', $non];
        yield 'gestionnaire' => [self::ACHETEUR, ['GESTIONNAIRE'], 'PAYEE', 'VENDUE', $non];
    }

    /** @param list<string> $roles */
    #[DataProvider('cas')]
    public function testDecision(string $utilisateurId, array $roles, string $statutCommande, string $statutCle, int $attendu): void
    {
        $cle = new CleRevelable('cle-1', 'offre-1', 'chiffre', self::ACHETEUR, $statutCommande, $statutCle, null);

        self::assertSame($attendu, (new CleLicenceVoter())->vote(self::jeton($utilisateurId, $roles), $cle, [CleLicenceVoter::REVELER]));
    }

    public function testNeSePrononcePasSurAutreChose(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new CleLicenceVoter())->vote(self::jeton(self::ACHETEUR, ['CLIENT']), null, [CleLicenceVoter::REVELER]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, (new CleLicenceVoter())->vote(
            self::jeton(self::ACHETEUR, ['CLIENT']),
            new CleRevelable('cle-1', 'offre-1', 'chiffre', self::ACHETEUR, 'PAYEE', 'VENDUE', null),
            ['AUTRE_ATTRIBUT'],
        ));
    }

    /** @param list<string> $roles */
    private static function jeton(string $id, array $roles): UsernamePasswordToken
    {
        $compte = new Compte($id, 'client@example.test', 'hash', 'Léa', 'Martin', 'fr', $roles, false, true, true);
        $utilisateur = new Utilisateur($compte);

        return new UsernamePasswordToken($utilisateur, 'api', $utilisateur->getRoles());
    }
}
