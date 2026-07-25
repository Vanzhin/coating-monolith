<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Exception;

use Symfony\Component\HttpFoundation\Response;

class AppException extends \Exception
{
    /**
     * @param array<string, mixed> $log
     */
    public function __construct(string $message = '', int $code = Response::HTTP_UNPROCESSABLE_ENTITY, private array $log = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLog(): array
    {
        return $this->log;
    }
}
