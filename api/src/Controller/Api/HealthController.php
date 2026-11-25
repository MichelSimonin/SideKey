<?php

declare(strict_types=1);

namespace App\Controller\Api;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sondes utilisées par la CI, le déploiement et la supervision.
 * Ne renvoient aucune information sensible (ni version, ni environnement, ni message d'erreur).
 *
 *  - /api/health        : l'application répond (vivacité), sans dépendance externe ;
 *  - /api/health/ready  : l'application peut servir (PostgreSQL et Redis joignables). C'est le test de
 *                         fumée de deploy/deploy.sh : une version qui échoue ici est remplacée par la précédente.
 */
final class HealthController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return $this->reponse(['status' => 'ok'], Response::HTTP_OK);
    }

    #[Route('/api/health/ready', name: 'api_health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $verifications = [
            'database' => $this->verifier('database', fn (): bool => 1 === (int) $this->connection->fetchOne('SELECT 1')),
            'redis' => $this->verifier('redis', fn (): bool => false !== $this->redis->ping()),
        ];
        $pret = !\in_array('ko', $verifications, true);

        return $this->reponse(
            ['status' => $pret ? 'ok' : 'ko', 'checks' => $verifications],
            $pret ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * @param callable(): bool $sonde
     *
     * @return 'ok'|'ko'
     */
    private function verifier(string $service, callable $sonde): string
    {
        try {
            return $sonde() ? 'ok' : 'ko';
        } catch (\Throwable $e) {
            // Le détail part dans le journal du serveur, jamais dans la réponse
            $this->logger->error('Sonde {service} en échec : {message}', ['service' => $service, 'message' => $e->getMessage()]);

            return 'ko';
        }
    }

    /**
     * @param array<string, mixed> $contenu
     */
    private function reponse(array $contenu, int $statut): JsonResponse
    {
        $response = new JsonResponse($contenu, $statut);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
