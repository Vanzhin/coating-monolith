<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\EventHandler;

use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Domain\Service\Mailer;
use App\Shared\Domain\Service\RedisService;
use App\Users\Domain\Event\LoginLinkCreatedEvent;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

readonly class LoginLinkCreatedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private RedisService $redisService,
        private Mailer $mailer,
        private UserRepositoryInterface $userRepository,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    public function __invoke(LoginLinkCreatedEvent $event): void
    {
        $user = $this->userRepository->getByUlid($event->userId);
        if ($user) {
            $hash = password_hash(random_bytes(10), PASSWORD_DEFAULT);
            $this->redisService->add($hash, ['userUlid' => $user->getUlid()], 60 * 5);
            $this->mailer->sendLoginLinkEmail(
                new Address($user->getEmail()->getValue(), 'Пользователь'),
                $this->urlGenerator->generate(
                    'app_login_by_link',
                    ['hash' => $hash],
                    UrlGeneratorInterface::ABSOLUTE_URL
                )
            );
        }
    }

}