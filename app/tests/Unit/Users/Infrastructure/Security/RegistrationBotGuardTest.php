<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Infrastructure\Security;

use App\Users\Infrastructure\Security\FormTimestampSigner;
use App\Users\Infrastructure\Security\RegistrationBotGuard;
use PHPUnit\Framework\TestCase;

final class RegistrationBotGuardTest extends TestCase
{
    private const SECRET = 'guard-secret';

    private function guard(): RegistrationBotGuard
    {
        return new RegistrationBotGuard(new FormTimestampSigner(self::SECRET));
    }

    private function tokenAged(int $ageSeconds): string
    {
        $ts = (string) (time() - $ageSeconds);

        return $ts.'.'.hash_hmac('sha256', $ts, self::SECRET);
    }

    public function test_filled_honeypot_is_automated(): void
    {
        // Даже с валидной старой меткой заполненный honeypot однозначно выдаёт бота.
        self::assertTrue($this->guard()->looksAutomated('http://spam.example', $this->tokenAged(10)));
    }

    public function test_missing_timestamp_is_automated(): void
    {
        self::assertTrue($this->guard()->looksAutomated(null, null));
    }

    public function test_tampered_timestamp_is_automated(): void
    {
        self::assertTrue($this->guard()->looksAutomated(null, 'garbage-token'));
    }

    public function test_too_fast_submission_is_automated(): void
    {
        // Свежая метка (возраст ~0) меньше порога MIN_FORM_AGE_SECONDS.
        $fresh = (new FormTimestampSigner(self::SECRET))->mint();

        self::assertTrue($this->guard()->looksAutomated(null, $fresh));
    }

    public function test_human_submission_passes(): void
    {
        // Пустой honeypot + метка старше порога = человек.
        self::assertFalse($this->guard()->looksAutomated('', $this->tokenAged(10)));
    }

    public function test_whitespace_honeypot_is_treated_as_empty(): void
    {
        // Пробелы в honeypot не считаем заполнением; решает метка (старая → человек).
        self::assertFalse($this->guard()->looksAutomated('   ', $this->tokenAged(10)));
    }
}
