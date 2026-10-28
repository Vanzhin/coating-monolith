<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Audit;

use App\Shared\Application\Message\MessageBusInterface;
use App\Shared\Infrastructure\Http\BodyCapper;
use App\Shared\Infrastructure\Http\HttpHeaderRedactor;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Retry\RetryStrategyInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Пишет телеметрию КАЖДОЙ попытки: RetryableHttpClient зовёт стратегию по завершении
 * попытки с её контекстом. Оборачивает штатную стратегию (решение о ретрае — её),
 * добавляя запись в аудит. Снимок запроса берёт из RequestSnapshotStore по user_data
 * (его положил CorrelatingHttpClient), ответную сторону — из контекста.
 *
 * Чтобы снять тело даже у успешного ответа (иначе RetryableHttpClient его не буферизует),
 * на не-ошибочном пути возвращаем null на первый вызов — это просит добуферить тело, —
 * и пишем запись на повторном вызове, где тело уже есть.
 *
 * Весь аудит best-effort: любое исключение глотаем в лог, решение о ретрае не искажаем.
 */
final readonly class AuditingRetryStrategy implements RetryStrategyInterface
{
    public function __construct(
        private RetryStrategyInterface $inner,
        private MessageBusInterface $bus,
        private RequestSnapshotStore $store,
        private HttpHeaderRedactor $redactor,
        private int $bodyMaxBytes,
        private LoggerInterface $logger,
    ) {
    }

    public function shouldRetry(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): ?bool
    {
        if (null !== $exception) {
            $decision = $this->inner->shouldRetry($context, $responseContent, $exception);
            $this->record($context, null, $exception, true === $decision);

            return $decision;
        }

        if (null === $responseContent) {
            // Тела ещё нет — просим RetryableHttpClient добуферить, чтобы снять его и у успеха.
            return null;
        }

        $decision = $this->inner->shouldRetry($context, $responseContent, null);
        $this->record($context, $responseContent, null, true === $decision);

        return $decision;
    }

    public function getDelay(AsyncContext $context, ?string $responseContent, ?TransportExceptionInterface $exception): int
    {
        return $this->inner->getDelay($context, $responseContent, $exception);
    }

    private function record(AsyncContext $context, ?string $body, ?TransportExceptionInterface $exception, bool $willRetry): void
    {
        try {
            $correlationId = (string) ($context->getInfo('user_data') ?? '');
            $snapshot = '' !== $correlationId ? $this->store->get($correlationId) : null;
            $attempt = '' !== $correlationId ? $this->store->nextAttempt($correlationId) : 1;

            [$host, $path, $query] = $this->splitUrl((string) $context->getInfo('url'));
            $cappedBody = BodyCapper::cap($body, $this->bodyMaxBytes);

            $statusCode = null;
            $responseHeaders = [];
            if (null === $exception) {
                try {
                    $statusCode = $context->getStatusCode();
                    $responseHeaders = $this->redactor->redactHeaders($context->getHeaders());
                } catch (\Throwable) {
                    // статус/заголовки недоступны — оставляем пустыми
                }
            }

            $record = new HttpAuditRecord(
                correlationId: '' !== $correlationId ? $correlationId : 'unknown',
                attemptNumber: $attempt,
                createdAt: new \DateTimeImmutable(),
                clientScope: $snapshot?->clientScope,
                method: $snapshot->method ?? (string) $context->getInfo('http_method'),
                host: $host,
                path: $path,
                query: $this->redactor->redactQuery($query),
                requestHeaders: $snapshot->headers ?? [],
                requestBody: $snapshot?->body,
                requestBodyTruncated: $snapshot->bodyTruncated ?? false,
                statusCode: $statusCode,
                responseHeaders: $responseHeaders,
                responseBody: $cappedBody['text'],
                responseBodyTruncated: $cappedBody['truncated'],
                durationMs: $this->durationMs($context),
                errorClass: null !== $exception ? $exception::class : null,
                errorMessage: $exception?->getMessage(),
            );

            $this->bus->executeMessages(new HttpAuditMessage($record));

            if (!$willRetry && '' !== $correlationId) {
                $this->store->forget($correlationId);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Аудит HTTP-попытки не снят', ['exception' => $e]);
        }
    }

    private function durationMs(AsyncContext $context): ?int
    {
        $total = $context->getInfo('total_time');

        return \is_numeric($total) ? (int) round(((float) $total) * 1000) : null;
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private function splitUrl(string $url): array
    {
        $parts = parse_url($url);

        return [$parts['host'] ?? '', $parts['path'] ?? '/', $parts['query'] ?? null];
    }
}
