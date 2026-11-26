<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Payment;

use App\Infrastructure\Payment\FournisseurPaiementSimule;
use App\Service\Paiement\CartePaiement;
use App\Service\Paiement\DemandeAutorisation;
use App\Service\Paiement\ResultatPaiement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CDC §11.6 : les quatre cartes de test donnent chacune la même réponse, à chaque appel (déterministe, testable en CI). */
final class FournisseurPaiementSimuleTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> numéro, statut, code de retour */
    public static function cartes(): iterable
    {
        yield '4242 : accepté' => ['4242 4242 4242 4242', ResultatPaiement::ACCEPTE, 'ok'];
        yield '4000 0002 : refusé' => ['4000 0000 0000 0002', ResultatPaiement::REFUSE, 'carte_refusee'];
        yield '4000 9995 : fonds insuffisants' => ['4000 0000 0000 9995', ResultatPaiement::REFUSE, 'fonds_insuffisants'];
        yield '4000 0119 : délai dépassé' => ['4000 0000 0000 0119', ResultatPaiement::ERREUR, 'delai_depasse'];
        yield 'autre carte valide : inconnue' => ['5555 5555 5555 4444', ResultatPaiement::REFUSE, 'carte_inconnue'];
        yield 'American Express : inconnue' => ['3782 822463 10005', ResultatPaiement::REFUSE, 'carte_inconnue'];
    }

    #[DataProvider('cartes')]
    public function testChaqueCarteDeTestDonneSaReponse(string $numero, string $statut, string $code): void
    {
        $prestataire = new FournisseurPaiementSimule();
        $demande = new DemandeAutorisation(4_999, CartePaiement::depuis($numero, '12/30', '123', new \DateTimeImmutable('2026-11-26')), str_repeat('a', 64));

        foreach ([1, 2, 3] as $appel) {
            $resultat = $prestataire->autoriser($demande);
            self::assertSame($statut, $resultat->statut, 'appel '.$appel);
            self::assertSame($code, $resultat->codeRetour);
        }
    }

    public function testSeulLAccepteEstAccepte(): void
    {
        self::assertTrue(ResultatPaiement::accepte()->estAccepte());
        self::assertFalse(ResultatPaiement::refuse('carte_refusee')->estAccepte());
        self::assertFalse(ResultatPaiement::erreur('delai_depasse')->estAccepte());
    }

    public function testLeMontantNeChangePasLaReponse(): void
    {
        // Le prestataire simulé ne décide que sur la carte : un gros montant n'est pas refusé ici (c'est l'anti-fraude qui juge)
        $carte = CartePaiement::depuis('4242424242424242', '12/30', '123', new \DateTimeImmutable('2026-11-26'));

        self::assertTrue((new FournisseurPaiementSimule())->autoriser(new DemandeAutorisation(9_999_999, $carte, str_repeat('b', 64)))->estAccepte());
    }
}
