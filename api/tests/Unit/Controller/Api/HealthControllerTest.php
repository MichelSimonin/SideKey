<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\HealthController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class HealthControllerTest extends TestCase
{
    public function testReturnsOkStatus(): void
    {
        $response = ($this->controleur())();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], json_decode((string) $response->getContent(), true));
    }

    public function testResponseIsNeverCached(): void
    {
        $response = ($this->controleur())();

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testDoesNotLeakEnvironmentDetails(): void
    {
        $payload = json_decode((string) ($this->controleur())()->getContent(), true);

        self::assertSame(['status'], array_keys($payload));
    }

    public function testLivenessDoesNotTouchDatabaseNorRedis(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method(self::anything());
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::never())->method(self::anything());

        (new HealthController($connection, $redis))();
    }

    public function testReadyWhenDatabaseAndRedisAnswer(): void
    {
        $response = $this->controleur()->ready();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['status' => 'ok', 'checks' => ['database' => 'ok', 'redis' => 'ok']],
            json_decode((string) $response->getContent(), true),
        );
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testNotReadyWhenDatabaseIsDown(): void
    {
        $response = $this->controleur(baseEnPanne: true)->ready();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(
            ['status' => 'ko', 'checks' => ['database' => 'ko', 'redis' => 'ok']],
            json_decode((string) $response->getContent(), true),
        );
    }

    public function testNotReadyWhenRedisIsDown(): void
    {
        $response = $this->controleur(redisEnPanne: true)->ready();

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('ko', json_decode((string) $response->getContent(), true)['checks']['redis']);
    }

    public function testFailureDetailsAreLoggedButNeverReturned(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with(self::anything(), self::callback(
                static fn (array $contexte): bool => 'database' === $contexte['service']
                    && str_contains($contexte['message'], 'mot de passe incorrect pour sk_app'),
            ));

        $response = $this->controleur(baseEnPanne: true, logger: $logger)->ready();

        self::assertStringNotContainsString('sk_app', (string) $response->getContent());
        self::assertStringNotContainsString('mot de passe', (string) $response->getContent());
    }

    private function controleur(
        bool $baseEnPanne = false,
        bool $redisEnPanne = false,
        ?LoggerInterface $logger = null,
    ): HealthController {
        $connection = $this->createStub(Connection::class);
        if ($baseEnPanne) {
            $connection->method('fetchOne')->willThrowException(new \RuntimeException('mot de passe incorrect pour sk_app'));
        } else {
            $connection->method('fetchOne')->willReturn(1);
        }

        $redis = $this->createStub(\Redis::class);
        if ($redisEnPanne) {
            $redis->method('ping')->willThrowException(new \RedisException('Connection refused'));
        } else {
            $redis->method('ping')->willReturn(true);
        }

        return null === $logger
            ? new HealthController($connection, $redis)
            : new HealthController($connection, $redis, $logger);
    }
}
