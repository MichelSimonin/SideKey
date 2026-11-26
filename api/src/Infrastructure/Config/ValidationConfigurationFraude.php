<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

use App\Service\Paiement\ConfigurationFraude;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Refuse une configuration anti-fraude dangereuse AU DÉPLOIEMENT (ADR-0008) : le réchauffage du cache (cache:warmup, lancé à
 * chaque construction de l'image et à chaque démarrage de l'environnement de test) construit ConfigurationFraude, dont le
 * constructeur rejette tout seuil hors bornes. Sans cela, un seuil mis à 0 par erreur désactiverait une protection sans
 * aucune erreur visible, et l'erreur n'apparaîtrait qu'au premier paiement.
 */
final class ValidationConfigurationFraude implements CacheWarmerInterface
{
    public function __construct(private readonly ConfigurationFraude $configuration)
    {
    }

    public function isOptional(): bool
    {
        return false;   // jamais ignoré, même en mode « optional warmers » désactivés
    }

    /** @return list<string> */
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        // La construction de $this->configuration a déjà validé les bornes ; rien à écrire dans le cache.
        return [];
    }
}
