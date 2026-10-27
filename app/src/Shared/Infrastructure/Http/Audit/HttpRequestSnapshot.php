<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Audit;

/**
 * Снимок запроса, общий для всех попыток одного логического вызова (ретрай шлёт тот же
 * запрос). Снимается один раз в CorrelatingHttpClient, кладётся в RequestSnapshotStore
 * и склеивается стратегией с ответной стороной каждой попытки. Секреты уже вырезаны,
 * тело уже обрезано.
 *
 * host/path/query здесь НЕ хранятся — абсолютный URL резолвится базовым клиентом и
 * доступен стратегии через getInfo('url').
 */
final readonly class HttpRequestSnapshot
{
    /** @param array<string, list<string>|string> $headers заголовки запроса (редактированы) */
    public function __construct(
        public string $correlationId,
        public string $clientScope,
        public string $method,
        public array $headers,
        public ?string $body,
        public bool $bodyTruncated,
    ) {
    }
}
