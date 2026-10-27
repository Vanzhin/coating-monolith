<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Audit;

/**
 * Мост между CorrelatingHttpClient (снимает запрос) и AuditingRetryStrategy (видит
 * только ответ): in-memory карта correlation_id → снимок запроса в пределах процесса.
 * Снимок держится, пока идут попытки логического запроса, и забывается, когда ретрай
 * принял финальное решение. Возможная мелкая утечка на исчерпанных ретраях приемлема —
 * процесс завершится.
 */
final class RequestSnapshotStore
{
    /** @var array<string, HttpRequestSnapshot> */
    private array $byCorrelationId = [];

    /** @var array<string, int> номер последней попытки по correlation_id */
    private array $attempts = [];

    public function put(HttpRequestSnapshot $snapshot): void
    {
        $this->byCorrelationId[$snapshot->correlationId] = $snapshot;
    }

    public function get(string $correlationId): ?HttpRequestSnapshot
    {
        return $this->byCorrelationId[$correlationId] ?? null;
    }

    /** Следующий номер попытки для логического запроса (1, 2, 3…). */
    public function nextAttempt(string $correlationId): int
    {
        return $this->attempts[$correlationId] = ($this->attempts[$correlationId] ?? 0) + 1;
    }

    public function forget(string $correlationId): void
    {
        unset($this->byCorrelationId[$correlationId], $this->attempts[$correlationId]);
    }
}
