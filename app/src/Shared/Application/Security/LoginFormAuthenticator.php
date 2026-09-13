<?php

namespace App\Shared\Application\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class LoginFormAuthenticator extends AbstractLoginFormAuthenticator implements AuthenticationEntryPointInterface
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_login';

    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
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
        // Явный _target_path из формы: ссылка «Войти» с любой публичной страницы несёт свой URL
        // (напр. калькулятор в /tools) — после входа возвращаемся туда. Пускаем только локальный
        // путь; Symfony сам _target_path не валидирует, поэтому open-redirect guard здесь.
        $targetPath = $request->request->get('_target_path');
        if (is_string($targetPath) && $this->isLocalPath($targetPath)) {
            return new RedirectResponse($targetPath);
        }

        // Иначе — сохранённый в сессии путь (аноним упёрся в защищённую страницу), затем кабинет.
        $session = $request->getSession();
        if ($sessionTarget = $this->getTargetPath($session, $firewallName)) {
            $this->removeTargetPath($session, $firewallName);

            return new RedirectResponse($sessionTarget);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_cabinet'));
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
    public function start(Request $request, ?\Symfony\Component\Security\Core\Exception\AuthenticationException $authException = null): Response
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
