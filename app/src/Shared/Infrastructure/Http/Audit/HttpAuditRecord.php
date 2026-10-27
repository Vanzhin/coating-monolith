<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Audit;

/**
 * Снимок одной физической попытки исходящего HTTP-запроса — чистая телеметрия,
 * без доменного смысла. Попытки одного логического запроса связаны correlationId,
 * нумеруются attemptNumber (ретраи — отдельные записи). Секреты уже вырезаны, тела
 * уже обрезаны на стороне декоратора; этот объект — только перенос готовых значений
 * до стока (через Messenger).
 */
final readonly class HttpAuditRecord
{
    /**
     * @param array<string, list<string>|string> $requestHeaders  заголовки запроса (Authorization и пр. редактированы)
     * @param array<string, list<string>|string> $responseHeaders заголовки ответа
     */
    public function __construct(
        public string $correlationId,
        public int $attemptNumber,
        public \DateTimeImmutable $createdAt,
        public ?string $clientScope,
        public string $method,
        public string $host,
        public string $path,
        public ?string $query,
        public array $requestHeaders,
        public ?string $requestBody,
        public bool $requestBodyTruncated,
        public ?int $statusCode,
        public array $responseHeaders,
        public ?string $responseBody,
        public bool $responseBodyTruncated,
        public ?int $durationMs,
        public ?string $errorClass,
        public ?string $errorMessage,
    ) {
    }
}
