<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Exception;

use Symfony\Component\HttpFoundation\Response;

class AppException extends \Exception
{
    public function __construct($message = '', $code = Response::HTTP_UNPROCESSABLE_ENTITY, private array $log = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getLog(): array
    {
        return $this->log;
    }
}
