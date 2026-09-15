<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Domain\Entity;

use App\Users\Domain\Entity\Token;
use PHPUnit\Framework\TestCase;

/**
 * Token обязан реконструироваться из хранилища в любом состоянии, включая истёкшее (fromArray) —
 * без исключения в конструкторе. Валидность отражает isValid()/getRemainingTimeInSeconds().
 */
final class TokenTest extends TestCase
{
    public function test_expired_token_reconstructs_without_throwing_and_reports_invalid(): void
    {
        $token = Token::fromArray([
            'token' => '123456',
            'subjectId' => 'subj-1',
            'expiresAt' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
        ]);

        self::assertFalse($token->isValid());
        self::assertSame(0, $token->getRemainingTimeInSeconds());
        self::assertSame('123456', $token->getToken());
        self::assertSame('subj-1', $token->getSubjectId());
    }

    public function test_active_token_is_valid_with_remaining_time(): void
    {
        $token = new Token('654321', 'subj-2', new \DateTimeImmutable('+5 minutes'));

        self::assertTrue($token->isValid());
        self::assertGreaterThan(0, $token->getRemainingTimeInSeconds());
    }

    public function test_equals_matches_only_same_code(): void
    {
        $token = new Token('111111', 'subj-3', new \DateTimeImmutable('+5 minutes'));

        self::assertTrue($token->equals('111111'));
        self::assertFalse($token->equals('222222'));
    }
}
