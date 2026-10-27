<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Application\Message\MessageBusInterface;
use App\Shared\Domain\Message\MessageInterface;
use App\Shared\Infrastructure\Http\Audit\RequestSnapshotStore;
use App\Shared\Infrastructure\Http\AuditedHttpClientFactory;
use App\Shared\Infrastructure\Http\HttpHeaderRedactor;
use App\Tests\Support\CollectingAuditBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;

final class AuditedHttpClientFactoryTest extends TestCase
{
    public function test_success_records_single_attempt_with_request_and_response(): void
    {
        $bus = new CollectingAuditBus();
        $client = $this->factory($bus)->wrap(
            new MockHttpClient([new MockResponse('{"ok":true}', ['http_code' => 200])]),
            'test_scope',
        );

        $response = $client->request('POST', 'https://api.example.com/v1/extract', [
            'json' => ['q' => 'hello'],
            'headers' => ['Authorization' => 'Bearer secret'],
        ]);
        self::assertSame('{"ok":true}', $response->getContent());

        self::assertCount(1, $bus->records);
        $record = $bus->records[0]->record;
        self::assertSame(1, $record->attemptNumber);
        self::assertSame('test_scope', $record->clientScope);
        self::assertSame('POST', $record->method);
        self::assertSame('api.example.com', $record->host);
        self::assertSame('/v1/extract', $record->path);
        self::assertSame(200, $record->statusCode);
        self::assertSame('{"q":"hello"}', $record->requestBody);
        self::assertSame('{"ok":true}', $record->responseBody);
        self::assertSame(HttpHeaderRedactor::REDACTED, $record->requestHeaders['Authorization'] ?? null);
    }

    public function test_failure_then_success_records_two_attempts_same_correlation(): void
    {
        $bus = new CollectingAuditBus();
        $client = $this->factory($bus)->wrap(
            new MockHttpClient([
                new MockResponse('err', ['http_code' => 500]),
                new MockResponse('{"ok":true}', ['http_code' => 200]),
            ]),
            'test_scope',
        );

        $response = $client->request('GET', 'https://api.example.com/v1/ping');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());

        self::assertCount(2, $bus->records);
        $first = $bus->records[0]->record;
        $second = $bus->records[1]->record;
        self::assertSame($first->correlationId, $second->correlationId, 'попытки одного запроса связаны');
        self::assertSame(1, $first->attemptNumber);
        self::assertSame(500, $first->statusCode);
        self::assertSame(2, $second->attemptNumber);
        self::assertSame(200, $second->statusCode);
    }

    public function test_audit_failure_does_not_break_the_call(): void
    {
        $bus = new class implements MessageBusInterface {
            public function executeMessages(MessageInterface ...$messages): void
            {
                throw new \RuntimeException('audit sink down');
            }

            public function execute(Envelope $envelope): void
            {
            }
        };
        $client = $this->factory($bus)->wrap(
            new MockHttpClient([new MockResponse('{"ok":true}', ['http_code' => 200])]),
            'test_scope',
        );

        $response = $client->request('GET', 'https://api.example.com/v1/ping');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"ok":true}', $response->getContent());
    }

    private function factory(MessageBusInterface $bus): AuditedHttpClientFactory
    {
        return new AuditedHttpClientFactory(
            bus: $bus,
            store: new RequestSnapshotStore(),
            redactor: new HttpHeaderRedactor(),
            logger: new NullLogger(),
            maxRetries: 2,
            retryDelayMs: 0,
            retryMultiplier: 1.0,
            bodyMaxBytes: 16384,
            auditEnabled: true,
        );
    }
}
