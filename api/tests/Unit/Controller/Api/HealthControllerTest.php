<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller\Api;

use App\Controller\Api\HealthController;
use PHPUnit\Framework\TestCase;

final class HealthControllerTest extends TestCase
{
    public function testReturnsOkStatus(): void
    {
        $response = (new HealthController())();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'ok'], json_decode((string) $response->getContent(), true));
    }

    public function testResponseIsNeverCached(): void
    {
        $response = (new HealthController())();

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testDoesNotLeakEnvironmentDetails(): void
    {
        $payload = json_decode((string) (new HealthController())()->getContent(), true);

        self::assertSame(['status'], array_keys($payload));
    }
}
