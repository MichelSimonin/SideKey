<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\EventSubscriber\ApiExceptionSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class ApiExceptionSubscriberTest extends TestCase
{
    private Translator $translator;

    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->translator = new Translator('fr');
        $this->translator->addLoader('array', new ArrayLoader());
        $this->translator->addResource('array', [
            'error.not_found' => 'Ressource introuvable.',
            'error.too_many_requests' => 'Trop de tentatives.',
            'error.internal' => 'Erreur interne.',
        ], 'fr', 'errors');
        $this->translator->addResource('array', ['error.not_found' => 'Resource not found.'], 'en', 'errors');
        $this->logs = [];
    }

    public function testHttpExceptionBecomesTranslatedJson(): void
    {
        $event = $this->dispatch('/api/jeux/inconnu', new NotFoundHttpException('Jeu 42 absent de la table jeu'), 'en');

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"message":"Resource not found."}', $response->getContent());
        self::assertStringNotContainsString('table', (string) $response->getContent());
    }

    public function testUnexpectedExceptionIsGeneric500WithCorrelationId(): void
    {
        $event = $this->dispatch('/api/commandes', new \RuntimeException('SQLSTATE[42P01] relation "commande" /var/www/api/src'));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(500, $response->getStatusCode());

        /** @var array{message: string, correlationId: string} $body */
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('Erreur interne.', $body['message']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $body['correlationId']);
        self::assertStringNotContainsString('SQLSTATE', (string) $response->getContent());

        // Le détail reste côté serveur, retrouvable par l'identifiant de corrélation.
        self::assertCount(1, $this->logs);
        self::assertSame($body['correlationId'], $this->logs[0]['context']['correlationId']);
    }

    public function testRetryAfterHeaderIsForwarded(): void
    {
        $event = $this->dispatch('/api/login', new TooManyRequestsHttpException(60));

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('60', $response->headers->get('Retry-After'));
    }

    public function testRequestsOutsideApiAreIgnored(): void
    {
        $event = $this->dispatch('/', new NotFoundHttpException());

        self::assertNull($event->getResponse());
    }

    private function dispatch(string $path, \Throwable $exception, string $locale = 'fr'): ExceptionEvent
    {
        $request = Request::create($path);
        $request->headers->set('Accept-Language', $locale); // Request::create() met « en-us » par défaut
        $kernel = $this->createStub(HttpKernelInterface::class);
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        (new ApiExceptionSubscriber($this->translator, $this->logger()))->onKernelException($event);

        return $event;
    }

    private function logger(): AbstractLogger
    {
        $logs = &$this->logs;

        return new class($logs) extends AbstractLogger {
            /** @param list<array{level: mixed, message: string, context: array<string, mixed>}> $logs */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
