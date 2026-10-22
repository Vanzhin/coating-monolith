<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Infrastructure\Twig;

use App\Notifications\Infrastructure\Twig\NotificationsTwigExtension;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\Service\UnreadNotificationCounterInterface;
use PHPUnit\Framework\TestCase;

/** Фильтр notification_text: экранирование → оживление только своих ссылок → переносы строк. */
final class NotificationsTwigExtensionTest extends TestCase
{
    public function test_escapes_html_and_emits_no_raw_tags(): void
    {
        $out = $this->ext()->notificationText('<script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_linkifies_own_host_url_and_breaks_lines(): void
    {
        $out = $this->ext()->notificationText(
            "Иванов Иван — срок подходит у позиций: 1.\nhttps://app.example.com/cabinet/compliance/dashboard?profile=p1",
        );

        self::assertStringContainsString(
            '<a href="https://app.example.com/cabinet/compliance/dashboard?profile=p1">',
            $out,
        );
        self::assertStringContainsString('<br', $out, 'перенос строки стал <br>');
    }

    public function test_foreign_url_stays_plain_text(): void
    {
        $out = $this->ext()->notificationText('Смотрите https://evil.example.org/phish прямо сейчас');

        self::assertStringNotContainsString('<a ', $out, 'чужой адрес не оживляем');
        self::assertStringContainsString('https://evil.example.org/phish', $out, 'но остаётся как текст');
    }

    public function test_query_ampersand_is_escaped_inside_href(): void
    {
        $out = $this->ext()->notificationText('https://app.example.com/cabinet/compliance/dashboard?status=overdue&only=1');

        self::assertStringContainsString(
            'href="https://app.example.com/cabinet/compliance/dashboard?status=overdue&amp;only=1"',
            $out,
        );
    }

    public function test_escaping_runs_before_linkify_so_url_does_not_swallow_following_tag(): void
    {
        $out = $this->ext()->notificationText('<b>https://app.example.com/x</b>');

        self::assertStringContainsString('&lt;b&gt;', $out, 'окружающий тег экранирован');
        self::assertStringContainsString('<a href="https://app.example.com/x">https://app.example.com/x</a>', $out);
    }

    public function test_relative_path_linkified_and_stays_relative_in_app(): void
    {
        $out = $this->ext()->notificationText(
            "Контроль обязанностей сотрудников\nИванов Иван — срок подходит у позиций: 1.\n/cabinet/compliance/dashboard?profile=p1",
        );

        self::assertStringContainsString(
            '<a href="/cabinet/compliance/dashboard?profile=p1">/cabinet/compliance/dashboard?profile=p1</a>',
            $out,
        );
        self::assertStringNotContainsString('https://', $out, 'в приложении хост не подставляем — путь относительный');
    }

    public function test_relative_path_absolutized_for_email(): void
    {
        $out = $this->ext()->notificationText(
            "Контроль обязанностей сотрудников\n/cabinet/compliance/dashboard?status=missing",
            absolute: true,
        );

        self::assertStringContainsString(
            '<a href="https://app.example.com/cabinet/compliance/dashboard?status=missing">',
            $out,
        );
    }

    public function test_path_query_ampersand_escaped_in_href_for_email(): void
    {
        $out = $this->ext()->notificationText(
            "x\n/cabinet/compliance/dashboard?status=overdue&only=1",
            absolute: true,
        );

        self::assertStringContainsString(
            'href="https://app.example.com/cabinet/compliance/dashboard?status=overdue&amp;only=1"',
            $out,
        );
    }

    private function ext(string $baseUrl = 'https://app.example.com'): NotificationsTwigExtension
    {
        return new NotificationsTwigExtension(
            $this->createStub(AuthUserFetcherInterface::class),
            $this->createStub(UnreadNotificationCounterInterface::class),
            $baseUrl,
        );
    }
}
