<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Response;

use App\Shared\Application\DTO\ResponseDTOTransformer;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class ResponseListener
{
    public const MIME_JSON = 'application/json';

    public function __construct(private readonly ResponseDTOTransformer $transformer)
    {
    }

    #[AsEventListener(priority: 200)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        $header = $event->getResponse()->headers->get('Content-Type');
        $statusCode = $event->getResponse()->getStatusCode();

        if (self::MIME_JSON === $header) {
            $response = new JsonResponse();
            $response->setStatusCode($statusCode);
            $data = $this->transformer->buildResponseData($event->getResponse());
            $response->setData($data);
            $event->setResponse($response);
        }
    }
}
