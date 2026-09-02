<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\RateLimiter;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Регресс на брутфорс OTP верификации канала: лимитеры должны существовать и реально
 * ограничивать число попыток. Если кто-то удалит/ослабит лимитер в framework.yaml —
 * тест упадёт. Ключ уникальный на прогон, чтобы sliding_window в Redis не флачил.
 */
final class ChannelVerifyLimiterTest extends KernelTestCase
{
    public function test_per_channel_limiter_blocks_after_five_attempts(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get('limiter.channel_verify_per_channel');
        self::assertInstanceOf(RateLimiterFactory::class, $factory);

        $limiter = $factory->create('test-channel-'.uniqid());

        for ($i = 1; $i <= 5; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted(), "Попытка $i из 5 должна проходить.");
        }
        self::assertFalse($limiter->consume()->isAccepted(), '6-я попытка на канал должна блокироваться.');
    }

    public function test_per_ip_limiter_blocks_after_twenty_attempts(): void
    {
        self::bootKernel();
        $factory = static::getContainer()->get('limiter.channel_verify_per_ip');
        self::assertInstanceOf(RateLimiterFactory::class, $factory);

        $limiter = $factory->create('test-ip-'.uniqid());

        for ($i = 1; $i <= 20; ++$i) {
            self::assertTrue($limiter->consume()->isAccepted(), "Попытка $i из 20 должна проходить.");
        }
        self::assertFalse($limiter->consume()->isAccepted(), '21-я попытка с IP должна блокироваться.');
    }
}
