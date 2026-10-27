<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\HttpHeaderRedactor;
use PHPUnit\Framework\TestCase;

final class HttpHeaderRedactorTest extends TestCase
{
    public function test_redacts_sensitive_headers_keeps_rest(): void
    {
        $redactor = new HttpHeaderRedactor();

        $out = $redactor->redactHeaders([
            'Authorization' => 'Bearer secret-token',
            'Cookie' => 'sid=abc',
            'X-Api-Key' => 'key123',
            'Content-Type' => 'application/json',
            'Accept' => ['application/json'],
        ]);

        self::assertSame(HttpHeaderRedactor::REDACTED, $out['Authorization']);
        self::assertSame(HttpHeaderRedactor::REDACTED, $out['Cookie']);
        self::assertSame(HttpHeaderRedactor::REDACTED, $out['X-Api-Key']);
        self::assertSame('application/json', $out['Content-Type']);
        self::assertSame(['application/json'], $out['Accept']);
    }

    public function test_redacts_query_secrets_keeps_rest(): void
    {
        $redactor = new HttpHeaderRedactor();

        parse_str($redactor->redactQuery('api_key=xxx&q=hello&access_token=yyy&page=2') ?? '', $out);

        self::assertSame(HttpHeaderRedactor::REDACTED, $out['api_key']);
        self::assertSame(HttpHeaderRedactor::REDACTED, $out['access_token']);
        self::assertSame('hello', $out['q']);
        self::assertSame('2', $out['page']);
    }

    public function test_null_query_passes_through(): void
    {
        self::assertNull((new HttpHeaderRedactor())->redactQuery(null));
    }
}
