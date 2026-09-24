<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * login_throttling на JWT-логине: после 5 неудачных попыток перебор упирается в лимит.
 * Свой REMOTE_ADDR — счётчик throttling'а cache-backed и НЕ откатывается DAMA; изолируем IP,
 * чтобы неудачи не отравили 127.0.0.1 (иначе успешный логин других тестов заблокировался бы —
 * лимит проверяется ДО пароля).
 */
final class ApiLoginThrottlingTest extends WebTestCase
{
    private const ISOLATED_IP = '198.51.100.7';

    public function test_repeated_failed_logins_get_throttled(): void
    {
        $client = static::createClient();
        $email = 'throttle_'.bin2hex(random_bytes(4)).'@example.com';

        for ($i = 1; $i <= 6; ++$i) {
            $this->attemptLogin($client, $email);
        }

        // Ответ — JSON с \uXXXX-эскейпом кириллицы; декодируем и сверяем поле message
        // (с Symfony 7.1 login_throttling-сообщение переводится на русский, было англ.).
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertStringContainsString(
            'Слишком много неудачных попыток входа',
            (string) ($payload['message'] ?? ''),
            'После 5 неудачных попыток вход должен упираться в login_throttling.',
        );
    }

    private function attemptLogin(KernelBrowser $client, string $email): void
    {
        $client->request(
            'POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => self::ISOLATED_IP],
            json_encode(['email' => $email, 'password' => 'wrong-password']),
        );
    }
}
