<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /sw.js отдаётся динамически (SwAction) как JS и несёт актуальные ассеты бандла в PRECACHE —
 * иначе калькуляторы /tools не работают офлайн. Логику самого SW (офлайн) проверяем в браузере.
 */
final class SwActionTest extends WebTestCase
{
    public function test_serves_dynamic_service_worker_with_bundle_in_precache(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sw.js');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('javascript', (string) $client->getResponse()->headers->get('Content-Type'));

        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString("const CACHE = 'app-", $body, 'версия кэша подставлена');
        self::assertStringContainsString('/app', $body, 'стартовый экран PWA в precache (иначе офлайн-запуск с иконки не откроется)');
        self::assertStringContainsString('/tools', $body, 'страницы калькуляторов в precache');
        self::assertStringContainsString('/tools/dew-point', $body, 'калькулятор точки росы в precache (офлайн)');
        self::assertStringContainsString('/build/', $body, 'ассеты бандла в precache (иначе офлайн-калькулятор не заведётся)');
        self::assertStringContainsString("addEventListener('install'", $body);
        self::assertStringContainsString("addEventListener('fetch'", $body);
    }
}
