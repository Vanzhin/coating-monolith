<?php

namespace App\Shared\Application\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;
use Symfony\Contracts\Translation\TranslatorInterface;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        // Нормализация к тому же виду, в котором Email VO хранит value в БД.
        // Symfony entity-provider (security.yaml: property: email.value) делает
        // case-sensitive findOneBy — без нормализации `Alexandr@x.ru` не найдёт
        // юзера, у которого в БД `alexandr@x.ru`.
        $email = strtolower(trim((string) $request->request->get('email')));

        return new Passport(
            new UserBadge($email),
            new PasswordCredentials($request->request->get('password')),
            [
                new CsrfTokenBadge('authenticate', $request->request->get('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $url = $this->resolveTargetUrl($request, $firewallName);

        // Инлайн-форма лендинга (AJAX) не умеет следовать 302 — отдаём URL в JSON,
        // навигацию делает фронт (inline-auth). Обычный POST — как раньше, редирект.
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['redirect' => $url]);
        }

        return new RedirectResponse($url);
    }

    // Куда отправить после входа. Явный _target_path из формы (ссылка «Войти» с публичной
    // страницы, напр. калькулятора) имеет приоритет — но только локальный путь (open-redirect
    // guard, Symfony сам _target_path не валидирует). Иначе — сохранённый в сессии путь
    // (аноним упёрся в защищённую страницу), затем кабинет.
    private function resolveTargetUrl(Request $request, string $firewallName): string
    {
        $targetPath = $request->request->get('_target_path');
        if (is_string($targetPath) && $this->isLocalPath($targetPath)) {
            return $targetPath;
        }

        $session = $request->getSession();
        if ($sessionTarget = $this->getTargetPath($session, $firewallName)) {
            $this->removeTargetPath($session, $firewallName);

            return $sessionTarget;
        }

        return $this->urlGenerator->generate('app_cabinet');
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // AJAX (инлайн-форма лендинга) — отдаём сообщение JSON'ом; ResponseListener завернёт
        // его в стандартный конверт {result:error, message}, фронт покажет тостом (как
        // остальные ошибки приложения). Форма и введённое остаются. Обычный POST — штатный
        // редирект на /login (ошибка в сессии).
        if ($request->isXmlHttpRequest()) {
            $message = $this->translator->trans($exception->getMessageKey(), $exception->getMessageData(), 'security');

            return new JsonResponse(['message' => $message], Response::HTTP_UNAUTHORIZED);
        }

        return parent::onAuthenticationFailure($request, $exception);
    }

    /**
     * Локальный путь: один ведущий «/», без «//» и «/\» (оба — protocol-relative,
     * увели бы на чужой хост). Схему/хост не пускаем — только внутренние переходы.
     */
    private function isLocalPath(string $path): bool
    {
        return str_starts_with($path, '/')
            && !str_starts_with($path, '//')
            && !str_starts_with($path, '/\\');
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }

    /**
     * Сохраняет исходный URL в сессии перед перенаправлением на страницу логина.
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        // Сохраняем исходный путь запроса в сессию
        // Используем полный URI для надежности
        $targetPath = $request->getRequestUri();

        // Удаляем базовый URL, если он есть, чтобы получить относительный путь
        $baseUrl = $request->getBaseUrl();
        if ($baseUrl && str_starts_with($targetPath, $baseUrl)) {
            $targetPath = substr($targetPath, strlen($baseUrl));
        }

        // Убеждаемся, что сессия существует и не сохраняем путь логина
        if ($request->hasSession() && '/login' !== $targetPath) {
            $this->saveTargetPath($request->getSession(), 'main', $targetPath);
        }

        return new RedirectResponse($this->getLoginUrl($request));
    }
}
