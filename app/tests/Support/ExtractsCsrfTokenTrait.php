<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Достаёт валидный CSRF-токен 'delete' для функциональных тестов мутаций. Токен session-wide
 * (csrf_token('delete') один на сессию), поэтому берём его с серверно рендерящейся admin-страницы,
 * где delete-модалка есть всегда (список подготовок поверхности — не JS-инфинит-лист). Тем же
 * клиентом (сессия переживает запрос через cookie) потом POST'им — как реальный пользователь.
 * Требует авторизованного ROLE_ADMIN клиента (иначе модалка под {% if canEdit %} не отрендерится).
 */
trait ExtractsCsrfTokenTrait
{
    protected function deleteCsrfToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/cabinet/coating/surface-treatment/list');
        $input = $crawler->filter('form[data-role="confirm-form"] input[name="_token"]');
        self::assertGreaterThan(0, $input->count(), 'Admin-страница должна рендерить delete-модалку с CSRF-токеном.');

        return (string) $input->first()->attr('value');
    }
}
