<?php

declare(strict_types=1);

namespace App\Tests\Support\Paiement;

use App\Tests\Support\Cles\ClesWebTestCase;

/**
 * Base des tests HTTP du paiement (F-07) : API réelle, PostgreSQL et Redis réels. Chaque test crée ses acheteurs (comptes
 * « cles-test-* », jamais supprimés : ils ont des commandes) et ses offres ; leurs compteurs Redis (fraud:*, rl:pay:*) et leurs
 * clés d'idempotence sont retirés à la fin.
 */
abstract class PaiementWebTestCase extends ClesWebTestCase
{
    public const OK = '4242424242424242';
    public const REFUSEE = '4000000000000002';
    public const FONDS = '4000000000009995';
    public const DELAI = '4000000000000119';

    /** @var list<string> */
    private array $acheteurs = [];
    /** @var list<string> */
    private array $empreintes = [];

    protected function tearDown(): void
    {
        foreach ($this->acheteurs as $id) {
            $this->redis->del('fraud:fails:'.$id, 'fraud:block:'.$id, 'fraud:keys24h:'.$id, 'rl:pay:'.$id);
        }
        foreach ($this->empreintes as $empreinte) {
            $this->redis->del('idem:'.$empreinte);
        }
        $this->acheteurs = $this->empreintes = [];
        parent::tearDown();
    }

    /**
     * Client à e-mail vérifié. Par défaut un compte ANCIEN : sans cela, tout panier de plus de 150 € serait « à vérifier »
     * (RG-14) et brouillerait les tests qui ne portent pas sur cette règle.
     *
     * @return array{id: string, email: string, jeton: string}
     */
    protected function acheteur(bool $ancien = true): array
    {
        $acheteur = $this->creerAcheteur();
        $this->connexion->executeStatement(
            'UPDATE utilisateur SET email_verifie = true'.($ancien ? ", cree_le = now() - interval '30 days'" : '').' WHERE id = CAST(:u AS uuid)',
            ['u' => $acheteur['id']],
        );
        $this->acheteurs[] = $acheteur['id'];

        return $acheteur + ['jeton' => $this->jetonPour($acheteur['email'])];
    }

    /** Offre approvisionnée en clés. */
    protected function offreEnStock(string $prix = '19.99', int $cles = 5): string
    {
        $offre = $this->creerOffre(prix: $prix);
        $this->stocker($offre, $cles);

        return $offre;
    }

    /**
     * Passe commande de $quantite clés d'une offre ; renvoie la référence.
     *
     * @param array{id: string, email: string, jeton: string} $acheteur
     */
    protected function commander(array $acheteur, string $offre, int $quantite = 1): string
    {
        $this->json('POST', '/api/panier/lignes', ['offreId' => $offre, 'quantite' => $quantite]);
        self::assertSame(200, $this->etat()['statut'], 'ajout au panier');
        $this->json('POST', '/api/commandes', ['confirmeMajeur' => false], $acheteur['jeton']);
        $etat = $this->etat();
        self::assertSame(201, $etat['statut'], 'commande : '.json_encode($etat['corps']));

        return (string) $etat['corps']['reference'];
    }

    /**
     * @param array{id: string, email: string, jeton: string} $acheteur
     * @param array<string, mixed>|null                       $corps     carte ; null : la carte OK
     *
     * @return array{statut: int, corps: array<string, mixed>}
     */
    protected function payer(string $reference, array $acheteur, ?array $corps = null, ?string $cle = null, string $langue = 'fr'): array
    {
        $corps ??= $this->carte(self::OK);
        $cle ??= bin2hex(random_bytes(12));
        $this->empreintes[] = hash('sha256', $acheteur['id']."
".$cle);   // retirée de Redis à la fin du test
        $this->json('POST', '/api/commandes/'.$reference.'/paiement', $corps, $acheteur['jeton'], ['HTTP_ACCEPT_LANGUAGE' => $langue, 'HTTP_IDEMPOTENCY_KEY' => $cle]);
        $etat = $this->etat();

        return ['statut' => $etat['statut'], 'corps' => $etat['corps']];
    }

    /** @return array<string, mixed> */
    protected function carte(string $numero): array
    {
        return ['numeroCarte' => $numero, 'expiration' => '12/30', 'cryptogramme' => '123'];
    }

    /** @return list<string> statuts des paiements d'une commande, du plus ancien au plus récent */
    protected function statutsPaiements(string $reference): array
    {
        return array_map('strval', $this->connexion->fetchFirstColumn(
            'SELECT p.statut FROM paiement p JOIN commande c ON c.id = p.commande_id WHERE c.reference = CAST(:r AS uuid) ORDER BY p.cree_le, p.id',
            ['r' => $reference],
        ));
    }

    protected function statutCommande(string $reference): string
    {
        return (string) $this->connexion->fetchOne('SELECT statut FROM commande WHERE reference = CAST(:r AS uuid)', ['r' => $reference]);
    }

    protected function clesPar(string $reference, string $statut): int
    {
        return (int) $this->connexion->fetchOne(
            'SELECT count(*) FROM cle_licence k JOIN ligne_commande l ON l.id = k.ligne_commande_id JOIN commande c ON c.id = l.commande_id WHERE c.reference = CAST(:r AS uuid) AND k.statut = :s',
            ['r' => $reference, 's' => $statut],
        );
    }
}
