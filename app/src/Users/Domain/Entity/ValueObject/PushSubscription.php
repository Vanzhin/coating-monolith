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
    /**
     * Хосты реальных push-сервисов. endpoint от браузера обязан быть https к одному из них — иначе
     * WebPushNotifier делал бы server-side POST на произвольный адрес (SSRF, напр. 169.254.169.254).
     * Суффикс с ведущей точкой = субдомены (web.push.apple.com), без точки = точный хост.
     */
    private const ALLOWED_ENDPOINT_HOSTS = [
        'fcm.googleapis.com',
        '.push.apple.com',
        'updates.push.services.mozilla.com',
        '.notify.windows.com',
    ];

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
     * Разбирает браузерный payload ({endpoint, keys:{p256dh, auth}}) — НЕДОВЕРЕННЫЙ вход, поэтому
     * тут же валидируем endpoint по allowlist push-сервисов (SSRF-инвариант). Любое отклонение — 422.
     */
    public static function fromBrowserPayload(mixed $payload): self
    {
        [$endpoint, $publicKey, $authToken] = self::parse($payload);
        self::assertAllowedEndpoint($endpoint);

        return new self($endpoint, $publicKey, $authToken);
    }

    /**
     * Гидрация из БД — данные уже прошли allowlist при создании; НЕ перепроверяем хост, чтобы смена
     * allowlist не ломала отправку по уже сохранённым подпискам.
     */
    public static function fromJson(string $json): self
    {
        [$endpoint, $publicKey, $authToken] = self::parse(json_decode($json, true));

        return new self($endpoint, $publicKey, $authToken);
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

    /**
     * @return array{string, string, string}
     */
    private static function parse(mixed $payload): array
    {
        $keys = is_array($payload) ? ($payload['keys'] ?? null) : null;
        $endpoint = is_array($payload) ? ($payload['endpoint'] ?? null) : null;
        $publicKey = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
        $authToken = is_array($keys) ? ($keys['auth'] ?? null) : null;

        if (!is_string($endpoint) || !is_string($publicKey) || !is_string($authToken)) {
            throw new AppException('Некорректная push-подписка.');
        }

        return [$endpoint, $publicKey, $authToken];
    }

    private static function assertAllowedEndpoint(string $endpoint): void
    {
        $parts = parse_url($endpoint);
        $scheme = $parts['scheme'] ?? null;
        $host = isset($parts['host']) ? strtolower($parts['host']) : null;

        if ('https' !== $scheme || null === $host || !self::isAllowedHost($host)) {
            throw new AppException('Недопустимый адрес push-подписки.');
        }
    }

    private static function isAllowedHost(string $host): bool
    {
        foreach (self::ALLOWED_ENDPOINT_HOSTS as $allowed) {
            $matches = str_starts_with($allowed, '.')
                ? str_ends_with($host, $allowed)
                : $host === $allowed;
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
