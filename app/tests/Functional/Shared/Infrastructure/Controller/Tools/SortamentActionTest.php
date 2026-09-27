<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Controller\Tools;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Эндпоинт сортамента публичен (аноним, без токена) и отдаёт дерево профилей в общем конверте
 * {result, status, data}. Фронт читает data и кэширует у себя для офлайна.
 */
final class SortamentActionTest extends WebTestCase
{
    private const URL = '/api/tools/section-factor/sortament';

    public function test_sortament_is_public_json_with_all_types(): void
    {
        $client = static::createClient();
        $client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('success', $body['result']);
        self::assertSame(['i_beam', 'channel', 'angle', 'rect_hollow'], array_keys($body['data']['types']));
    }

    public function test_sortament_leaf_carries_section_dimensions(): void
    {
        $client = static::createClient();
        $client->request('GET', self::URL);

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        // двутавр 20Б1 по ГОСТ Р 57837: Тип Б → Номер 20 → Вариант 1
        $leaf = $body['data']['types']['i_beam']['standards']['gost-r-57837-2017']['tree']['Б']['20']['1'];
        self::assertSame(200, $leaf['height']);
        self::assertSame(5.5, $leaf['webThickness']);
    }
}
