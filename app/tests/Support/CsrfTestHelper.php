<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Проставляет валидный CSRF-токен (intention 'mutation') дефолтным заголовком X-CSRF-TOKEN
 * на KernelBrowser, чтобы контроллер-тесты мутаций проходили глобальный CsrfRequestSubscriber.
 *
 * Токен нельзя сминтить вне HTTP-запроса (SessionTokenStorage требует активной сессии), поэтому
 * берём его из <meta name="csrf-token"> отрендеренной страницы: base.html.twig кладёт туда
 * csrf_token('mutation'), привязанный к текущей сессии. Публичный '/' рендерит base и всегда 200.
 * Зовём ПОСЛЕ loginUser — тогда токен привязан к той же сессии, что и последующие POST.
 */
final class CsrfTestHelper
{
    public static function enable(KernelBrowser $client): void
    {
        // disableReboot: без перезагрузки kernel'а GET '/' не сбрасывает TokenStorage,
        // а значит admin-токен от loginUser переживает запрос — иначе прямые dispatch'и
        // команд в фикстурах теста (с canManage()) упали бы в Forbidden. Reboot возвращаем.
        $follow = $client->isFollowingRedirects();
        $client->disableReboot();
        $client->followRedirects(true);
        $crawler = $client->request('GET', '/');
        $client->followRedirects($follow);
        $client->enableReboot();

        $meta = $crawler->filter('meta[name="csrf-token"]');
        if ($meta->count() > 0) {
            $client->setServerParameter('HTTP_X_CSRF_TOKEN', $meta->attr('content'));
        }
    }
}
