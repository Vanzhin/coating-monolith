<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Audit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;

/**
 * Пишет телеметрию исходящих запросов в таблицу http_audit_log (Postgres). Первая и
 * пока единственная реализация HttpAuditSink; при росте объёма меняется на ClickHouse
 * подменой этого класса в конфиге — декоратор и места вызова не трогаются.
 */
final readonly class PostgresHttpAuditSink implements HttpAuditSink
{
    public function __construct(private Connection $connection)
    {
    }

    public function record(HttpAuditRecord $record): void
    {
        $this->connection->insert('http_audit_log', [
            'correlation_id' => $record->correlationId,
            'attempt_number' => $record->attemptNumber,
            'created_at' => $record->createdAt,
            'client_scope' => $record->clientScope,
            'method' => $record->method,
            'host' => $record->host,
            'path' => $record->path,
            'query' => $record->query,
            'request_headers' => $this->asJson($record->requestHeaders),
            'request_body' => $record->requestBody,
            'request_body_truncated' => $record->requestBodyTruncated,
            'status_code' => $record->statusCode,
            'response_headers' => $this->asJson($record->responseHeaders),
            'response_body' => $record->responseBody,
            'response_body_truncated' => $record->responseBodyTruncated,
            'duration_ms' => $record->durationMs,
            'error_class' => $record->errorClass,
            'error_message' => $record->errorMessage,
        ], [
            'created_at' => Types::DATETIMETZ_IMMUTABLE,
            'request_body_truncated' => Types::BOOLEAN,
            'response_body_truncated' => Types::BOOLEAN,
        ]);
    }

    /** @param array<string, list<string>|string> $headers */
    private function asJson(array $headers): string
    {
        if ([] === $headers) {
            return '{}';
        }

        return json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
