<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Message\MessageBusInterface;
use App\Shared\Infrastructure\Http\Audit\AuditingRetryStrategy;
use App\Shared\Infrastructure\Http\Audit\RequestSnapshotStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Retry\GenericRetryStrategy;
use Symfony\Component\HttpClient\RetryableHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Оборачивает scoped-клиент интеграции в стек «ретрай + аудит»:
 * CorrelatingHttpClient( RetryableHttpClient( base, AuditingRetryStrategy ) ).
 * Все числа (попытки/задержка/множитель/лимит тела/рубильник) — из конфига, не зашиты.
 * Интеграция (NetAngels и пр.) зовёт wrap() на свой scoped-клиент.
 */
final readonly class AuditedHttpClientFactory
{
    public function __construct(
        private MessageBusInterface $bus,
        private RequestSnapshotStore $store,
        private HttpHeaderRedactor $redactor,
        private LoggerInterface $logger,
        private int $maxRetries,
        private int $retryDelayMs,
        private float $retryMultiplier,
        private int $bodyMaxBytes,
        private bool $auditEnabled,
    ) {
    }

    public function wrap(HttpClientInterface $base, string $scope): HttpClientInterface
    {
        $genericStrategy = new GenericRetryStrategy(
            GenericRetryStrategy::DEFAULT_RETRY_STATUS_CODES,
            $this->retryDelayMs,
            $this->retryMultiplier,
        );

        if (!$this->auditEnabled) {
            return new RetryableHttpClient($base, $genericStrategy, $this->maxRetries, $this->logger);
        }

        $strategy = new AuditingRetryStrategy(
            $genericStrategy,
            $this->bus,
            $this->store,
            $this->redactor,
            $this->bodyMaxBytes,
            $this->logger,
        );

        $retryable = new RetryableHttpClient($base, $strategy, $this->maxRetries, $this->logger);

        return new CorrelatingHttpClient($retryable, $scope, $this->redactor, $this->store, $this->bodyMaxBytes);
    }
}
