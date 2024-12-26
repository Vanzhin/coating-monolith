<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use App\Shared\Infrastructure\Exception\AppException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Webmozart\Assert\InvalidArgumentException;


class BaseController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
    )
    {
    }

    protected function getClientErrorMessage(\Throwable $error): string
    {
        if (in_array($error::class, [AppException::class, InvalidArgumentException::class])) {
            return $error->getMessage();
        }
        $this->logger->error($error->getMessage());

        return 'Внутренняя ошибка сервера.';
    }
}
