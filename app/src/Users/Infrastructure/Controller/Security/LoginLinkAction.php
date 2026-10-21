<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Security;

use App\Shared\Application\Event\EventBusInterface;
use App\Users\Domain\Event\LoginLinkCreatedEvent;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/login_link', name: 'app_login_link')]
class LoginLinkAction extends AbstractController
{
    public function __construct(
        private readonly EventBusInterface $eventBus,
        private readonly UserRepositoryInterface $userRepository,
        // Rate limiters — Symfony auto-wire по имени: framework.rate_limiter.<name>
        // → RateLimiterFactoryInterface <name>Limiter (см. config/packages/framework.yaml).
        #[Autowire(service: 'limiter.login_link_per_email')]
        private readonly RateLimiterFactoryInterface $loginLinkPerEmailLimiter,
        #[Autowire(service: 'limiter.login_link_per_ip')]
        private readonly RateLimiterFactoryInterface $loginLinkPerIpLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }

        if (!$request->isMethod('POST')) {
            return $this->render('security/login_link.html.twig', ['email' => null]);
        }

        $emailRaw = $request->getPayload()->get('email');
        $email = is_string($emailRaw) ? $emailRaw : null;

        // IP-лимит применяется всегда — защита от bot'ов, тайминг-атак, энумерации.
        // Превышение — та же форма с alert'ом, без технической 429-страницы.
        $ipLimit = $this->loginLinkPerIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$ipLimit->isAccepted()) {
            $retryMinutes = max(1, (int) ceil(($ipLimit->getRetryAfter()->getTimestamp() - time()) / 60));

            return $this->renderOutcome($request, $email, sprintf('Слишком много попыток. Попробуйте через %d мин.', $retryMinutes));
        }

        // Anti-enumeration: не сообщаем, зарегистрирован такой email или нет. Отправляем
        // только существующему и не исчерпавшему per-email лимит; при превышении молча
        // пропускаем отправку — ответ одинаков. При сбое SMTP — generic-ошибка (без намёка
        // на существование адреса сверх самого факта сбоя).
        $user = null !== $email ? $this->userRepository->getByEmail($email) : null;
        if (null !== $user && $this->loginLinkPerEmailLimiter->create(mb_strtolower($email))->consume()->isAccepted()) {
            try {
                $this->eventBus->execute(new LoginLinkCreatedEvent($user->getUlid()));
            } catch (\Throwable) {
                // Письмо шлётся синхронно в обработчике; messenger заворачивает сбой в
                // HandlerFailedException. Показываем понятное, пользователь остаётся на месте.
                return $this->renderOutcome($request, $email, 'Не удалось отправить письмо. Попробуйте ещё раз через пару минут.');
            }
        }

        return $this->renderOutcome($request, $email, null);
    }

    // Один рендер для обоих каналов: AJAX (свап-панель лендинга) — фрагмент; обычный
    // запрос — полная страница. И успех, и ошибка — терминальное сообщение без формы
    // (error задан → текст ошибки, иначе → «проверьте почту»), чтобы не спамили отправку.
    private function renderOutcome(Request $request, ?string $email, ?string $error): Response
    {
        // И успех, и ошибка — терминальное сообщение без формы: не даём долбить отправку.
        if ($request->isXmlHttpRequest()) {
            return $this->render('security/_login_link_result.html.twig', [
                'email' => $email,
                'error' => $error,
            ]);
        }

        return $this->render('security/login_link_sent.html.twig', ['email' => $email, 'error' => $error]);
    }
}
