<?php

declare(strict_types=1);

namespace App\Service\Paiement;

/**
 * Seuils anti-fraude du niveau « Équilibré » (RG-14, CDC §6.3, ADR-0008), lus dans la configuration Symfony
 * (paramètres sidekey.fraude.*) : on les ajuste sans toucher au code.
 *
 * Un seuil mis à zéro ou absurde désactiverait une protection SANS erreur visible : le constructeur refuse toute valeur
 * hors des bornes ci-dessous. La construction est exercée à chaque déploiement par ValidationConfigurationFraude
 * (réchauffage du cache) et par les tests : une configuration dangereuse empêche le déploiement.
 */
final class ConfigurationFraude
{
    /**
     * @param int $clesMax24h                 clés au plus par client sur 24 h glissantes
     * @param int $echecsMax                  échecs de paiement avant blocage
     * @param int $blocageSecondes            durée du blocage (et de la fenêtre qui compte les échecs)
     * @param int $compteRecentHeures         un compte plus jeune est « récent »
     * @param int $seuilCompteRecentEuros     montant au-delà duquel une commande d'un compte récent est « à vérifier »
     * @param int $seuilPremiereCommandeEuros montant au-delà duquel une première commande est « à vérifier »
     */
    public function __construct(
        public readonly int $clesMax24h,
        public readonly int $echecsMax,
        public readonly int $blocageSecondes,
        public readonly int $compteRecentHeures,
        public readonly int $seuilCompteRecentEuros,
        public readonly int $seuilPremiereCommandeEuros,
    ) {
        self::borne('clés par 24 h', $clesMax24h, 1, 1_000);
        self::borne('échecs avant blocage', $echecsMax, 1, 100);
        self::borne('durée du blocage (s)', $blocageSecondes, 60, 7 * 86_400);
        self::borne('ancienneté d\'un compte récent (h)', $compteRecentHeures, 1, 720);
        self::borne('seuil d\'un compte récent (€)', $seuilCompteRecentEuros, 1, 100_000);
        self::borne('seuil d\'une première commande (€)', $seuilPremiereCommandeEuros, 1, 100_000);
    }

    private static function borne(string $nom, int $valeur, int $min, int $max): void
    {
        if ($valeur < $min || $valeur > $max) {
            throw new \InvalidArgumentException(\sprintf('Configuration anti-fraude invalide : %s = %d (attendu de %d à %d).', $nom, $valeur, $min, $max));
        }
    }
}
