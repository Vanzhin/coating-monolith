<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Twig;

use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\Service\UnreadNotificationCounterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Хелперы шаблонов уведомлений:
 * - unread_notifications_count — число непрочитанных текущего юзера для бейджа в шелле (ленивое, кэш на запрос);
 * - notification_text — безопасный рендер текста уведомления: экранирование → оживление ТОЛЬКО своих ссылок
 *   (хост из APP_BASE_URL; чужой адрес остаётся текстом) → перенос строк в <br>. Текст готовит проходчик/рендерер,
 *   шаблон только отображает. Один фильтр на список и на письмо — единый безопасный вывод.
 */
class NotificationsTwigExtension extends AbstractExtension
{
    private ?int $unread = null;
    private readonly ?string $ownHost;

    public function __construct(
        private readonly AuthUserFetcherInterface $authUserFetcher,
        private readonly UnreadNotificationCounterInterface $unreadCounter,
        string $appBaseUrl,
    ) {
        $host = parse_url($appBaseUrl, PHP_URL_HOST);
        $this->ownHost = \is_string($host) ? $host : null;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('unread_notifications_count', [$this, 'unreadCount']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('notification_text', [$this, 'notificationText'], ['is_safe' => ['html']]),
        ];
    }

    public function unreadCount(): int
    {
        if (!$this->authUserFetcher->isAuthenticated()) {
            return 0;
        }

        return $this->unread ??= $this->unreadCounter->countForOwner($this->authUserFetcher->getAuthUserId());
    }

    public function notificationText(string $message): string
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return nl2br($this->linkifyOwnUrls($escaped), false);
    }

    /** Оживляет http(s)-ссылки на наш хост; чужие адреса остаются экранированным текстом. */
    private function linkifyOwnUrls(string $escaped): string
    {
        // Текст уже экранирован: литеральных «<» нет, а «&» встречается и в разделителе запроса (стал &amp;),
        // и в хвостах экранированных тегов/кавычек (&lt;, &quot;...). Берём обычные символы URL плюс только &amp;,
        // чтобы ссылка не заглатывала идущий следом экранированный тег.
        return preg_replace_callback(
            '~https?://(?:[^\s<&]|&amp;)+~u',
            fn (array $m): string => $this->isOwnUrl($m[0])
                ? sprintf('<a href="%s">%s</a>', $m[0], $m[0])
                : $m[0],
            $escaped,
        ) ?? $escaped;
    }

    private function isOwnUrl(string $url): bool
    {
        if (null === $this->ownHost) {
            return false;
        }
        $host = parse_url(htmlspecialchars_decode($url, ENT_QUOTES), PHP_URL_HOST);

        return \is_string($host) && 0 === strcasecmp($host, $this->ownHost);
    }
}
