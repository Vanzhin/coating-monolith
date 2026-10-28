<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\Audit\HttpRequestSnapshot;
use App\Shared\Infrastructure\Http\Audit\RequestSnapshotStore;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Внешний слой аудируемого клиента: снимает ЗАПРОС (метод, заголовки, тело — редактировано
 * и обрезано), кладёт снимок в RequestSnapshotStore по correlation_id и штампует id в опцию
 * user_data, чтобы AuditingRetryStrategy (внутри ретрая) смогла связать ответ каждой попытки
 * с этим запросом. user_data зарезервирован под аудит у аудируемого клиента.
 */
final class CorrelatingHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public function __construct(
        HttpClientInterface $client,
        private readonly string $clientScope,
        private readonly HttpHeaderRedactor $redactor,
        private readonly RequestSnapshotStore $store,
        private readonly int $bodyMaxBytes,
    ) {
        $this->client = $client;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $correlationId = Uuid::v4()->toRfc4122();
        $body = BodyCapper::cap($this->extractBody($options), $this->bodyMaxBytes);

        $this->store->put(new HttpRequestSnapshot(
            correlationId: $correlationId,
            clientScope: $this->clientScope,
            method: $method,
            headers: $this->redactor->redactHeaders($this->headersFrom($options)),
            body: $body['text'],
            bodyTruncated: $body['truncated'],
        ));

        $options['user_data'] = $correlationId;

        return $this->client->request($method, $url, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, list<string>|string>
     */
    private function headersFrom(array $options): array
    {
        $headers = $options['headers'] ?? [];

        return \is_array($headers) ? $headers : [];
    }

    /** @param array<string, mixed> $options */
    private function extractBody(array $options): ?string
    {
        if (isset($options['json'])) {
            return json_encode($options['json'], JSON_UNESCAPED_UNICODE) ?: null;
        }

        $body = $options['body'] ?? null;

        return \is_string($body) ? $body : null;
    }
}
