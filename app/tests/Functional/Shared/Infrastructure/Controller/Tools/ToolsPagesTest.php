<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Infrastructure\Controller\Tools;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Раздел «Инструменты» публичен: страницы отдаются анониму (без залогиненного юзера) и
 * содержат серверный контент (важно для SEO — краулер видит H1/текст).
 */
final class ToolsPagesTest extends WebTestCase
{
    public function test_tools_index_is_public_and_renders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tools');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Инструменты');
    }

    public function test_mix_calculator_is_public_and_renders(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/tools/mix');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'смешивания');
        // Серверная разметка калькулятора и SEO-текст присутствуют для краулера.
        self::assertSelectorExists('[data-controller="mix-calculator"]');
        self::assertSelectorExists('details');
    }

    public function test_film_calculator_is_public_and_renders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tools/film');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'плёнки');
        self::assertSelectorExists('[data-controller="film-calculator"]');
        self::assertSelectorExists('details');
    }

    public function test_consumption_calculator_is_public_and_renders(): void
    {
        $client = static::createClient();
        $client->request('GET', '/tools/consumption');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'расход');
        self::assertSelectorExists('[data-controller="consumption-calculator"]');
        self::assertSelectorExists('details');
    }
}
