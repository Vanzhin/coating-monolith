<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Infrastructure\Service;

use App\Shared\Domain\Aggregate\VerificationSubjectInterface;
use App\Users\Domain\Entity\Token;
use App\Users\Domain\Repository\TokenRepositoryInterface;
use App\Users\Infrastructure\Service\TokenService;
use PHPUnit\Framework\TestCase;

/**
 * getTimeUntilNextToken не должен возвращать отрицательное для истёкшего-но-ещё-лежащего токена —
 * иначе throttle заблокировал бы новую выдачу с «через ~-N мин».
 */
final class TokenServiceTest extends TestCase
{
    public function test_expired_existing_token_yields_zero_cooldown(): void
    {
        self::assertSame(0, $this->cooldownFor(new \DateTimeImmutable('-1 minute')));
    }

    public function test_active_existing_token_yields_positive_cooldown(): void
    {
        self::assertGreaterThan(0, $this->cooldownFor(new \DateTimeImmutable('+2 minutes')));
    }

    public function test_no_existing_token_yields_zero_cooldown(): void
    {
        self::assertSame(0, $this->cooldownFor(null));
    }

    private function cooldownFor(?\DateTimeImmutable $expiresAt): int
    {
        $repo = $this->createMock(TokenRepositoryInterface::class);
        $repo->method('findBySubject')->willReturn(
            null === $expiresAt ? null : new Token('123456', 'subj', $expiresAt)
        );
        $subject = $this->createMock(VerificationSubjectInterface::class);
        $subject->method('getSubjectId')->willReturn('subj');

        return (new TokenService($repo))->getTimeUntilNextToken($subject);
    }
}
