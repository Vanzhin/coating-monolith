<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\EventListener\Response;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Security-заголовки должны стоять на обычных ответах (проверяем на публичном '/').
 */
final class SecurityHeadersListenerTest extends WebTestCase
{
    public function test_security_headers_present(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $headers = $client->getResponse()->headers;
        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'));
        self::assertNotNull($headers->get('Content-Security-Policy-Report-Only'));
        self::assertStringContainsString("frame-ancestors 'none'", (string) $headers->get('Content-Security-Policy-Report-Only'));
    }
}
