<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Подписка браузера на web push. То, что PushManager.subscribe() отдаёт фронту и что мы храним
 * в Channel->value для канала WEB_PUSH: адрес доставки (endpoint) и пара ключей шифрования payload
 * (p256dh — публичный ключ клиента, auth — секрет аутентификации). Без любого из трёх пуш собрать
 * нельзя, поэтому пустые значения — невалидная подписка.
 *
 * Единая точка разбора/валидации формата: браузерный JSON приходит как {endpoint, keys:{p256dh, auth}},
 * в этом же виде и сериализуемся обратно (fromJson/toJson) — так формат живёт в одном месте, а не
 * растекается по контроллеру, хендлеру и нотифаеру.
 */
final readonly class PushSubscription implements \JsonSerializable
{
    public function __construct(
        public string $endpoint,
        public string $publicKey,
        public string $authToken,
    ) {
        if ('' === $endpoint || '' === $publicKey || '' === $authToken) {
            throw new AppException('Некорректная push-подписка.');
        }
    }

    /**
     * Разбирает браузерный payload ({endpoint, keys:{p256dh, auth}}). Любое отклонение формата —
     * невалидная подписка (HTTP 422).
     */
    public static function fromBrowserPayload(mixed $payload): self
    {
        $keys = is_array($payload) ? ($payload['keys'] ?? null) : null;
        $endpoint = is_array($payload) ? ($payload['endpoint'] ?? null) : null;
        $publicKey = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
        $authToken = is_array($keys) ? ($keys['auth'] ?? null) : null;

        if (!is_string($endpoint) || !is_string($publicKey) || !is_string($authToken)) {
            throw new AppException('Некорректная push-подписка.');
        }

        return new self($endpoint, $publicKey, $authToken);
    }

    public static function fromJson(string $json): self
    {
        return self::fromBrowserPayload(json_decode($json, true));
    }

    public function toJson(): string
    {
        return (string) json_encode($this, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}}
     */
    public function jsonSerialize(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'keys' => ['p256dh' => $this->publicKey, 'auth' => $this->authToken],
        ];
    }
}
