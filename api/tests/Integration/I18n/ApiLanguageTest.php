<?php

declare(strict_types=1);

namespace App\Tests\Integration\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * F-02 : les messages d'erreur de l'API suivent l'en-tête Accept-Language,
 * limité au français et à l'anglais, avec repli sur le français.
 */
final class ApiLanguageTest extends WebTestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function languages(): iterable
    {
        yield 'français' => ['fr', 'Ressource introuvable.', 'fr'];
        yield 'anglais' => ['en', 'Resource not found.', 'en'];
        yield 'anglais régional avec poids' => ['en-US,en;q=0.9', 'Resource not found.', 'en'];
        yield 'préférence pondérée' => ['de-DE,en;q=0.8,fr;q=0.5', 'Resource not found.', 'en'];
        yield 'langue non gérée : repli sur le français' => ['de-DE', 'Ressource introuvable.', 'fr'];
    }

    #[DataProvider('languages')]
    public function testNotFoundMessageFollowsAcceptLanguage(string $acceptLanguage, string $message, string $contentLanguage): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/route-inexistante', server: ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage]);

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertResponseHeaderSame('Content-Language', $contentLanguage);
        self::assertJsonStringEqualsJsonString(
            (string) json_encode(['message' => $message]),
            (string) $client->getResponse()->getContent(),
        );
    }

    public function testWithoutAcceptLanguageDefaultsToFrench(): void
    {
        $client = static::createClient();
        // Le client de test envoie « en-us » par défaut : on simule un en-tête absent.
        $client->request('GET', '/api/route-inexistante', server: ['HTTP_ACCEPT_LANGUAGE' => '']);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('Ressource introuvable.', (string) $client->getResponse()->getContent());
    }

    public function testMethodNotAllowedIsTranslatedAndKeepsAllowHeader(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/health', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);

        self::assertResponseStatusCodeSame(405);
        self::assertResponseHeaderSame('Allow', 'GET');
        self::assertJsonStringEqualsJsonString(
            '{"message":"Method not allowed for this resource."}',
            (string) $client->getResponse()->getContent(),
        );
    }
}
