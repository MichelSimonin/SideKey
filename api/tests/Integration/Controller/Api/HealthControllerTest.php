<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthControllerTest extends WebTestCase
{
    public function testHealthEndpointAnswersJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/health');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertJsonStringEqualsJsonString('{"status":"ok"}', (string) $client->getResponse()->getContent());
    }

    public function testHealthEndpointRejectsWriteMethods(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/health');

        self::assertResponseStatusCodeSame(405);
    }
}
