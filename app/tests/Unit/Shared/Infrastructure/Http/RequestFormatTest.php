<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Http\RequestFormat;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestFormatTest extends TestCase
{
    private static function request(string $accept, bool $xhr = false): Request
    {
        $request = Request::create('/cabinet/x', 'POST');
        if ('' !== $accept) {
            $request->headers->set('Accept', $accept);
        }
        if ($xhr) {
            $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        }

        return $request;
    }

    public function test_expects_json(): void
    {
        self::assertTrue(RequestFormat::expectsJson(self::request('application/json')));
        self::assertTrue(RequestFormat::expectsJson(self::request('text/html,application/json'))); // расширенный Accept
        self::assertTrue(RequestFormat::expectsJson(self::request('text/html', xhr: true)));        // AJAX
        self::assertFalse(RequestFormat::expectsJson(self::request('text/html')));
    }

    public function test_is_html(): void
    {
        self::assertTrue(RequestFormat::isHtml(self::request('text/html,application/xhtml+xml')));
        self::assertTrue(RequestFormat::isHtml(self::request('')));   // без Accept — браузер по умолчанию
        self::assertTrue(RequestFormat::isHtml(self::request('*/*')));
        self::assertFalse(RequestFormat::isHtml(self::request('application/json')));
        self::assertFalse(RequestFormat::isHtml(self::request('text/html', xhr: true))); // AJAX — не HTML-страница
    }
}
