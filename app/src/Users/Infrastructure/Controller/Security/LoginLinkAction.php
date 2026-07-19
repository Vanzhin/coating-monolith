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
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/login_link', name: 'app_login_link')]
class LoginLinkAction extends AbstractController
{
    public function __construct(
        private readonly EventBusInterface $eventBus,
        private readonly UserRepositoryInterface $userRepository,
        // Rate limiters — Symfony auto-wire по имени: framework.rate_limiter.<name>
        // → RateLimiterFactory <name>Limiter (см. config/packages/framework.yaml).
        #[Autowire(service: 'limiter.login_link_per_email')]
        private readonly RateLimiterFactory $loginLinkPerEmailLimiter,
        #[Autowire(service: 'limiter.login_link_per_ip')]
        private readonly RateLimiterFactory $loginLinkPerIpLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $email = null;
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }
        if ($request->isMethod('POST')) {
            $email = $request->getPayload()->get('email');

            // IP-лимит применяется всегда — защита от bot'ов, тайминг-атак,
            // энумерации. Превышение — рендерим ту же форму с alert'ом, чтобы
            // пользователь не видел техническую 429-страницу.
            $ipLimit = $this->loginLinkPerIpLimiter->create($request->getClientIp() ?? 'unknown')->consume();
            if (!$ipLimit->isAccepted()) {
                return $this->render('security/login_link.html.twig', [
                    'email' => $email,
                    'error' => sprintf(
                        'Слишком много попыток. Попробуйте через %d мин.',
                        max(1, (int) ceil(($ipLimit->getRetryAfter()->getTimestamp() - time()) / 60)),
                    ),
                ]);
            }

            // Anti-enumeration: не сообщаем, зарегистрирован такой email или нет.
            // Плюс per-email лимит — не даём флудить конкретный ящик, даже если
            // атакующий меняет IP. При превышении молча пропускаем отправку —
            // одинаковый ответ независимо от того, лимит это или отсутствие юзера.
            $user = is_string($email) ? $this->userRepository->getByEmail($email) : null;
            if (null !== $user) {
                $emailLimit = $this->loginLinkPerEmailLimiter->create(mb_strtolower($email))->consume();
                if ($emailLimit->isAccepted()) {
                    $this->eventBus->execute(new LoginLinkCreatedEvent($user->getUlid()));
                }
            }

            return $this->render('security/login_link_sent.html.twig', compact('email'));
        }

        return $this->render('security/login_link.html.twig', compact('email'));
    }
}
