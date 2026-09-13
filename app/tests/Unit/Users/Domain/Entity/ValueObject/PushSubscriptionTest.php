<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Domain\Entity\ValueObject;

use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Entity\ValueObject\PushSubscription;
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

    /**
     * @dataProvider malformedPayloads
     */
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
}
