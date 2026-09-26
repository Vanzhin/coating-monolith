<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Domain\Entity\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Entity\ValueObject\PushSubscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PushSubscriptionTest extends TestCase
{
    private const VALID = [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'keys' => ['p256dh' => 'BEl-publicKey', 'auth' => 'authToken'],
    ];

    public function test_builds_from_browser_payload(): void
    {
        $subscription = PushSubscription::fromBrowserPayload(self::VALID);

        self::assertSame('https://fcm.googleapis.com/fcm/send/abc123', $subscription->endpoint);
        self::assertSame('BEl-publicKey', $subscription->publicKey);
        self::assertSame('authToken', $subscription->authToken);
    }

    public function test_json_round_trip_preserves_wire_shape(): void
    {
        $subscription = PushSubscription::fromBrowserPayload(self::VALID);

        self::assertSame(self::VALID, PushSubscription::fromJson($subscription->toJson())->jsonSerialize());
    }

    #[DataProvider('malformedPayloads')]
    public function test_rejects_malformed_payload(mixed $payload): void
    {
        $this->expectException(AppException::class);
        PushSubscription::fromBrowserPayload($payload);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedPayloads(): iterable
    {
        yield 'не массив' => ['строка'];
        yield 'нет endpoint' => [['keys' => ['p256dh' => 'p', 'auth' => 'a']]];
        yield 'нет p256dh' => [['endpoint' => 'e', 'keys' => ['auth' => 'a']]];
        yield 'нет auth' => [['endpoint' => 'e', 'keys' => ['p256dh' => 'p']]];
        yield 'пустой endpoint' => [['endpoint' => '', 'keys' => ['p256dh' => 'p', 'auth' => 'a']]];
        yield 'keys не массив' => [['endpoint' => 'e', 'keys' => 'x']];
    }

    #[DataProvider('disallowedEndpoints')]
    public function test_rejects_disallowed_endpoint(string $endpoint): void
    {
        $this->expectException(AppException::class);
        PushSubscription::fromBrowserPayload([
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'p', 'auth' => 'a'],
        ]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disallowedEndpoints(): iterable
    {
        yield 'http (не https)' => ['http://fcm.googleapis.com/fcm/send/x'];
        yield 'SSRF: link-local IP' => ['https://169.254.169.254/latest/meta-data/'];
        yield 'SSRF: localhost' => ['https://localhost/x'];
        yield 'чужой хост' => ['https://evil.example.com/x'];
        yield 'near-miss apple' => ['https://notpush.apple.com/x'];
    }

    #[DataProvider('allowedEndpoints')]
    public function test_accepts_allowlisted_endpoint(string $endpoint): void
    {
        $sub = PushSubscription::fromBrowserPayload([
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'p', 'auth' => 'a'],
        ]);

        self::assertSame($endpoint, $sub->endpoint);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedEndpoints(): iterable
    {
        yield 'FCM (Chrome)' => ['https://fcm.googleapis.com/fcm/send/x'];
        yield 'Apple (Safari)' => ['https://web.push.apple.com/xxx'];
        yield 'Mozilla (Firefox)' => ['https://updates.push.services.mozilla.com/wpush/v2/xxx'];
        yield 'WNS (Edge)' => ['https://db5.notify.windows.com/w/?token=xxx'];
    }

    public function test_from_json_does_not_enforce_allowlist(): void
    {
        // Хранимые подписки грузим лениво: смена allowlist не должна ломать отправку по ним.
        $json = (string) json_encode([
            'endpoint' => 'https://legacy.push.example/x',
            'keys' => ['p256dh' => 'p', 'auth' => 'a'],
        ]);

        self::assertSame('https://legacy.push.example/x', PushSubscription::fromJson($json)->endpoint);
    }
}
