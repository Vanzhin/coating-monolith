<?php

declare(strict_types=1);

namespace App\Users\Application\Security\EventSubscriber;

use App\Users\Domain\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Глобальный гейт: пускает inactive (т.е. без верифицированных каналов) пользователя
 * только на whitelist-маршруты; на любые другие — редиректит на страницу верификации.
 *
 * Слушает CONTROLLER, потому что:
 *  - firewall уже выполнил аутентификацию (TokenStorage заполнен реальным юзером),
 *  - роутер уже определил _route (можно матчить по имени, не по path),
 *  - до вызова контроллера мы успеваем подменить его на возврат RedirectResponse.
 */
final class ChannelVerificationGate implements EventSubscriberInterface
{
    /**
     * Маршруты, доступные inactive юзеру. Любой маршрут вне этого списка для inactive
     * закончится редиректом на верификацию. Исключения:
     *  - login/logout/signup — без них юзер не сможет войти.
     *  - страница верификации и её сопутствующие экшены.
     *  - публичный homepage.
     *  - login-link flow (магические ссылки).
     */
    private const PUBLIC_ROUTES = [
        'app_homepage',
        'app_login',
        'app_logout',
        'app_sign_up',
        'app_login_link',
        'app_login_link_process',
        'app_user_channel_verification',
        'app_user_channel_send_token',
        'app_user_channel_create',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if ($route === null || in_array($route, self::PUBLIC_ROUTES, true)) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return; // anonymous — пусть firewall разруливает
        }
        if ($user->isActive()) {
            return;
        }

        $event->setController(fn () => new RedirectResponse(
            $this->urlGenerator->generate('app_user_channel_verification')
        ));
    }
}
