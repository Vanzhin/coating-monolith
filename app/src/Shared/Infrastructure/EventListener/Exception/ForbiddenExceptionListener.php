<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Exception;

use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Twig\Environment;

/**
 * Маппит ForbiddenException в HTTP 403. Приоритет выше общего 422-листенера
 * (AppExceptionHtmlListener, 195) и останавливает распространение — иначе тот
 * перезатёр бы ответ на 422 (ForbiddenException — потомок AppException).
 */
#[AsEventListener(event: 'kernel.exception', priority: 200)]
final class ForbiddenExceptionListener
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof ForbiddenException) {
            return;
        }

        $status = Response::HTTP_FORBIDDEN;
        $request = $event->getRequest();

        if ($this->acceptsJson($request)) {
            $event->setResponse(new JsonResponse(['message' => $throwable->getMessage()], $status));
        } else {
            $content = $this->twig->render('bundles/TwigBundle/Exception/error403.html.twig', [
                'status_code' => $status,
                'status_text' => Response::$statusTexts[$status] ?? 'Forbidden',
                'message' => $throwable->getMessage(),
            ]);
            $event->setResponse(new Response($content, $status));
        }

        $event->stopPropagation();
    }

    private function acceptsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json');
    }
}
