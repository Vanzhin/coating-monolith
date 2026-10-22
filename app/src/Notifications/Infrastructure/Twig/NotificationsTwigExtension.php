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
 * - notification_text — безопасный рендер текста уведомления: экранирование → оживление своих ссылок
 *   (относительные пути + абсолютные URL на наш хост; чужой адрес остаётся текстом) → перенос строк в <br>.
 *   Один фильтр на список и на письмо; флаг absolute абсолютизирует относительные пути для письма (почте
 *   нужен хост), в приложении путь остаётся относительным (origin резолвит браузер). Текст готовит рендерер.
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

    /**
     * @param bool $absolute письмо требует абсолютных ссылок (почте некуда резолвить относительный путь) —
     *                       подставляем хост из DOMAIN; в приложении (false) путь остаётся относительным,
     *                       браузер резолвит к текущему origin (хост в коде не зашиваем)
     */
    public function notificationText(string $message, bool $absolute = false): string
    {
        $escaped = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = $this->linkifyOwnUrls($escaped);

        return nl2br($this->linkifyPaths($linked, $absolute), false);
    }

    /**
     * Оживляет относительные пути-ссылки (каждая на своей строке, начинается с «/»). В приложении путь
     * остаётся как есть (origin резолвит браузер); в письме абсолютизируем хостом DOMAIN.
     */
    private function linkifyPaths(string $escaped, bool $absolute): string
    {
        $prefix = $absolute && null !== $this->ownHost ? 'https://'.$this->ownHost : '';

        return preg_replace_callback(
            '~^/(?:[^\s<&]|&amp;)+~mu',
            fn (array $m): string => sprintf('<a href="%1$s%2$s">%1$s%2$s</a>', $prefix, $m[0]),
            $escaped,
        ) ?? $escaped;
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
