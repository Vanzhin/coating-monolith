<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Exception;

use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Twig\Environment;

#[AsEventListener(event: 'kernel.exception', priority: 195)]
class AppExceptionHtmlListener
{
    public function __construct(private readonly Environment $twig)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof AppException) {
            return;
        }

        $request = $event->getRequest();
        // Только для HTML-запросов (не JSON — для JSON есть отдельный листенер)
        if (!$this->acceptsHtml($request)) {
            return;
        }

        $content = $this->twig->render('bundles/TwigBundle/Exception/error422.html.twig', [
            'status_code' => Response::HTTP_UNPROCESSABLE_ENTITY,
            'status_text' => Response::$statusTexts[Response::HTTP_UNPROCESSABLE_ENTITY] ?? 'Unprocessable Entity',
            'message' => $throwable->getMessage(),
        ]);

        $response = new Response($content, Response::HTTP_UNPROCESSABLE_ENTITY);
        $event->setResponse($response);
    }

    private function acceptsHtml(Request $request): bool
    {
        $accept = $request->headers->get('Accept', 'text/html');
        return str_contains($accept, 'text/html');
    }
}


