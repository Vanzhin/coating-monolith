<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Http\Audit;

use App\Shared\Infrastructure\Http\Audit\HttpAuditRecord;
use App\Shared\Infrastructure\Http\Audit\PostgresHttpAuditSink;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PostgresHttpAuditSinkTest extends KernelTestCase
{
    public function test_persists_attempt_row_with_jsonb_and_flags(): void
    {
        self::bootKernel();
        $conn = static::getContainer()->get(Connection::class);
        \assert($conn instanceof Connection);
        $sink = new PostgresHttpAuditSink($conn);

        $correlationId = Uuid::v4()->toRfc4122();
        $sink->record(new HttpAuditRecord(
            correlationId: $correlationId,
            attemptNumber: 2,
            createdAt: new \DateTimeImmutable('2026-01-01T10:00:00+00:00'),
            clientScope: 'netangels',
            method: 'POST',
            host: 'api.example.com',
            path: '/v1/extract',
            query: 'a=1',
            requestHeaders: ['Authorization' => '[REDACTED]', 'Content-Type' => 'application/json'],
            requestBody: '{"q":"hello"}',
            requestBodyTruncated: false,
            statusCode: 200,
            responseHeaders: ['content-type' => ['application/json']],
            responseBody: '{"ok":true}',
            responseBodyTruncated: true,
            durationMs: 42,
            errorClass: null,
            errorMessage: null,
        ));

        $row = $conn->fetchAssociative(
            'SELECT * FROM http_audit_log WHERE correlation_id = ?',
            [$correlationId],
        );

        self::assertIsArray($row);
        self::assertSame(2, (int) $row['attempt_number']);
        self::assertSame('netangels', $row['client_scope']);
        self::assertSame('POST', $row['method']);
        self::assertSame('api.example.com', $row['host']);
        self::assertSame(200, (int) $row['status_code']);
        self::assertSame('{"ok":true}', $row['response_body']);
        self::assertTrue((bool) $row['response_body_truncated']);
        self::assertFalse((bool) $row['request_body_truncated']);
        self::assertSame(42, (int) $row['duration_ms']);

        $headers = json_decode((string) $row['request_headers'], true);
        self::assertSame('[REDACTED]', $headers['Authorization']);
    }
}
