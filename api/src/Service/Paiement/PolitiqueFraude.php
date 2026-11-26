<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Règles de décision de l'anti-fraude « Équilibré » (RG-14). Logique pure, sans accès aux données ni à Redis : elle se
 * teste à la frontière (149,99 €, 150,00 €, 150,01 €), ce qui est là que les erreurs de comparaison se cachent.
 *
 * Les comparaisons sont STRICTES comme dans le cahier des charges : « dépasse 150 € » signifie plus de 150,00 € ;
 * « moins de 24 h » signifie un compte créé il y a strictement moins de 24 h. Les montants sont des centimes entiers,
 * jamais des flottants.
 */
final class PolitiqueFraude
{
    public function __construct(private readonly ConfigurationFraude $configuration)
    {
    }

    /** Acheter $nouvelles clés de plus porterait le total sur 24 h glissantes au-delà du plafond. */
    public function limiteClesAtteinte(int $dejaAchetees, int $nouvelles): bool
    {
        return $dejaAchetees + $nouvelles > $this->configuration->clesMax24h;
    }

    public function plafondCles(): int
    {
        return $this->configuration->clesMax24h;
    }

    /**
     * La commande est-elle à vérifier par un administrateur ? Oui si :
     *  - le compte a moins de 24 h ET le montant dépasse 150 € ; ou
     *  - c'est la première commande du client ET le montant dépasse 200 €.
     * Les deux notions de temps sont distinctes : l'ancienneté se mesure depuis la création du compte, la vérification de
     * l'e-mail est une condition d'achat à part (CommandeService).
     */
    public function commandeSuspecte(ContextePaiement $contexte, \DateTimeImmutable $maintenant): bool
    {
        $limiteAnciennete = $maintenant->modify(\sprintf('-%d hours', $this->configuration->compteRecentHeures));
        $compteRecent = $contexte->compteCreeLe > $limiteAnciennete;

        if ($compteRecent && $contexte->montantCentimes > $this->configuration->seuilCompteRecentEuros * 100) {
            return true;
        }

        return $contexte->premiereCommande && $contexte->montantCentimes > $this->configuration->seuilPremiereCommandeEuros * 100;
    }
}
