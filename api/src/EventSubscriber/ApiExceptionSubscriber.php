<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Transforme toute exception levée sous /api en réponse JSON au message traduit
 * selon la langue de la requête (Accept-Language, F-02).
 *
 * Fail Securely : le corps ne contient jamais le message de l'exception, une trace ou
 * un chemin ; une erreur inattendue devient un 500 générique avec un identifiant de
 * corrélation, le détail restant dans les journaux du serveur.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    /** Code HTTP → clé de traduction (domaine « errors »). */
    private const MESSAGES = [
        Response::HTTP_BAD_REQUEST => 'error.bad_request',
        Response::HTTP_UNAUTHORIZED => 'error.unauthorized',
        Response::HTTP_FORBIDDEN => 'error.forbidden',
        Response::HTTP_NOT_FOUND => 'error.not_found',
        Response::HTTP_METHOD_NOT_ALLOWED => 'error.method_not_allowed',
        Response::HTTP_NOT_ACCEPTABLE => 'error.not_acceptable',
        Response::HTTP_CONFLICT => 'error.conflict',
        Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'error.unsupported_media_type',
        Response::HTTP_UNPROCESSABLE_ENTITY => 'error.unprocessable',
        Response::HTTP_TOO_MANY_REQUESTS => 'error.too_many_requests',
    ];

    /** En-têtes d'une HttpException qu'on peut renvoyer au client sans fuite. */
    private const FORWARDED_HEADERS = ['Allow', 'Retry-After', 'WWW-Authenticate'];

    /**
     * @param list<string> $enabledLocales
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.enabled_locales%')]
        private readonly array $enabledLocales = ['fr', 'en'],
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale = 'fr',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onKernelException'];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $locale = $this->resolveLocale($request);

        if ($status >= 500) {
            $correlationId = bin2hex(random_bytes(8));
            $this->logger->error('Erreur interne de l\'API', [
                'correlationId' => $correlationId,
                'exception' => $exception,
                'path' => $request->getPathInfo(),
            ]);
            $body = [
                'message' => $this->translator->trans('error.internal', [], 'errors', $locale),
                'correlationId' => $correlationId,
            ];
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
        } else {
            $key = self::MESSAGES[$status] ?? 'error.bad_request';
            $body = ['message' => $this->translator->trans($key, [], 'errors', $locale)];
        }

        $response = new JsonResponse($body, $status);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Content-Language', $locale);
        $response->setVary('Accept-Language', false);
        if ($exception instanceof HttpExceptionInterface) {
            foreach (self::FORWARDED_HEADERS as $name) {
                if (isset($exception->getHeaders()[$name])) {
                    $response->headers->set($name, (string) $exception->getHeaders()[$name]);
                }
            }
        }

        $event->setResponse($response);
    }

    /**
     * Une erreur de routage (404, 405) survient avant que le LocaleListener de Symfony
     * n'applique Accept-Language : la langue est donc résolue ici, avec la même règle
     * (langue gérée la mieux pondérée, sinon français).
     */
    private function resolveLocale(Request $request): string
    {
        $locale = $request->attributes->get('_locale');
        if (\is_string($locale) && \in_array($locale, $this->enabledLocales, true)) {
            return $locale;
        }

        if (!$request->headers->has('Accept-Language')) {
            return $this->defaultLocale;
        }

        // Accept-Language sans langue gérée : getPreferredLanguage() renverrait la première
        // langue de la liste ; on veut explicitement la langue par défaut.
        $preferred = $request->getPreferredLanguage($this->enabledLocales);
        $asked = array_map(static fn (string $tag): string => strtolower(substr($tag, 0, 2)), $request->getLanguages());

        return null !== $preferred && \in_array(substr($preferred, 0, 2), $asked, true) ? $preferred : $this->defaultLocale;
    }
}
