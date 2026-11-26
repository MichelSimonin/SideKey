<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Config;

use App\Infrastructure\Config\ValidationConfigurationFraude;
use App\Service\Paiement\ConfigurationFraude;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * ADR-0008 : une configuration anti-fraude dangereuse empêche le déploiement. Le réchauffage du cache (cache:warmup) instancie
 * ConfigurationFraude, dont le constructeur refuse un seuil hors bornes ; le réchauffeur ne doit donc jamais être « optionnel ».
 * (Vérifié à la main : un seuil à 0 fait échouer `bin/console cache:warmup` avec « Configuration anti-fraude invalide ».)
 */
final class ValidationConfigurationFraudeTest extends TestCase
{
    public function testLeReechauffeurEstObligatoireEtNEcritRienDansLeCache(): void
    {
        $validation = new ValidationConfigurationFraude(new ConfigurationFraude(10, 3, 3600, 24, 150, 200));

        self::assertInstanceOf(CacheWarmerInterface::class, $validation);
        self::assertFalse($validation->isOptional(), 'jamais ignoré, même quand les réchauffeurs optionnels sont désactivés');
        self::assertSame([], $validation->warmUp('/tmp/cache'));
    }

    public function testUneConfigurationInvalideEmpecheLaConstructionDuReechauffeur(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ValidationConfigurationFraude(new ConfigurationFraude(10, 0, 3600, 24, 150, 200));
    }
}
