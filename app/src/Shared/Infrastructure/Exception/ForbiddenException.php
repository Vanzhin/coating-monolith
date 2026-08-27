<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Отказ авторизации: актор аутентифицирован, но не вправе выполнить операцию.
 * Кидается из Application-хендлеров (через AccessControl). Маппится в HTTP 403
 * отдельным листенером (ForbiddenExceptionListener), а не общим 422-листенером.
 */
final class ForbiddenException extends AppException
{
    /**
     * @param array<string, mixed> $log
     */
    public function __construct(string $message = 'Недостаточно прав для этого действия.', array $log = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, Response::HTTP_FORBIDDEN, $log, $previous);
    }
}
