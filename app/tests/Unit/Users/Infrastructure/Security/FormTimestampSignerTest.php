<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Infrastructure\Security;

use App\Users\Infrastructure\Security\FormTimestampSigner;
use PHPUnit\Framework\TestCase;

final class FormTimestampSignerTest extends TestCase
{
    private const SECRET = 'test-secret-value';

    public function test_mint_then_seconds_since_is_near_zero(): void
    {
        $signer = new FormTimestampSigner(self::SECRET);

        $age = $signer->secondsSince($signer->mint());

        self::assertNotNull($age);
        self::assertGreaterThanOrEqual(0, $age);
        self::assertLessThanOrEqual(2, $age);
    }

    public function test_null_token_returns_null(): void
    {
        self::assertNull((new FormTimestampSigner(self::SECRET))->secondsSince(null));
    }

    public function test_malformed_token_returns_null(): void
    {
        self::assertNull((new FormTimestampSigner(self::SECRET))->secondsSince('no-dot-here'));
    }

    public function test_non_numeric_timestamp_returns_null(): void
    {
        // подпись корректна для строки 'abc', но ts не число → метка невалидна
        $bad = 'abc.'.hash_hmac('sha256', 'abc', self::SECRET);

        self::assertNull((new FormTimestampSigner(self::SECRET))->secondsSince($bad));
    }

    public function test_tampered_signature_returns_null(): void
    {
        $ts = (string) (time() - 5);

        self::assertNull((new FormTimestampSigner(self::SECRET))->secondsSince($ts.'.deadbeef'));
    }

    public function test_token_signed_with_other_secret_returns_null(): void
    {
        $minted = (new FormTimestampSigner('other-secret'))->mint();

        self::assertNull((new FormTimestampSigner(self::SECRET))->secondsSince($minted));
    }

    public function test_returns_elapsed_seconds_for_old_valid_token(): void
    {
        $ts = (string) (time() - 10);
        $token = $ts.'.'.hash_hmac('sha256', $ts, self::SECRET);

        $age = (new FormTimestampSigner(self::SECRET))->secondsSince($token);

        self::assertNotNull($age);
        self::assertGreaterThanOrEqual(10, $age);
    }
}
