<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Exception;

use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ExceptionListener
{
    public const MIME_JSON = 'application/json';

    public function __construct(private ContainerBagInterface $containerBag)
    {
    }

    #[AsEventListener(priority: 190)]
    public function onKernelException(ExceptionEvent $event): void
    {
        // Получаем MIME тип из заголовка Accept
        $acceptHeader = $event->getRequest()->headers->get('Accept');

        if (self::MIME_JSON === $acceptHeader) {
            $exception = $event->getThrowable();
            $response = new JsonResponse();
            $response->setData($this->exceptionToArray($exception));

            // HttpException содержит информацию о заголовках и статусе, испольузем это
            if ($exception instanceof HttpExceptionInterface) {
                $response->setStatusCode($exception->getStatusCode());
                $response->headers->replace($exception->getHeaders());
            } else {
                $response->setStatusCode(Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            $event->setResponse($response);
        }
    }

    /**
     * @return array<string,string>\
     */
    public function exceptionToArray(\Throwable $exception): array
    {
        $debug = (bool) $this->containerBag->get('kernel.debug');
        // Человекочитаемое сообщение несут только AppException (наши доменные ошибки) и
        // HttpException (framework-уровень). Всё остальное (DBAL/SQL, TypeError, SMTP и т.п.)
        // в проде маскируем — иначе утекает схема БД/внутренности. Под debug показываем как есть.
        $clientSafe = $exception instanceof AppException || $exception instanceof HttpExceptionInterface;

        $data = [
            'message' => ($debug || $clientSafe) ? $exception->getMessage() : 'Internal Server Error',
        ];
        if ($debug) {
            $data = array_merge(
                $data,
                [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTrace(),
                ]
            );
        }

        return $data;
    }
}
