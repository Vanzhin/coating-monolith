<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\EventHandler;

use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Service\ChannelNotifierService;
use App\Users\Domain\Event\ChannelVerifiedEvent;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Service\Validation\EmailValidatorInterface;
use Psr\Log\LoggerInterface;

readonly class ChannelVerifiedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private ChannelRepositoryInterface $channelRepository,
        private EmailValidatorInterface $emailValidator,
        private ChannelNotifierService $channelNotifierService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ChannelVerifiedEvent $event): void
    {
        $channel = $this->channelRepository->findById($event->channelId);

        if ($channel) {
            $user = $channel->getOwner();
            if (!$this->emailValidator->isEmailValid($user->getEmail())) {
                throw new AppException(sprintf('Пользователь с email `%s` не может быть активирован.', $user->getEmail()->getValue()));
            }
            $user->makeActiveInternally();
            $this->userRepository->add($user);

            // Подтверждающее уведомление — best-effort: сетевой сбой (напр. SMTP 451) НЕ должен
            // откатывать верификацию канала и активацию пользователя. Логируем и продолжаем.
            try {
                $this->channelNotifierService->notify($channel, 'Канал верифицирован');
            } catch (\Throwable $e) {
                $this->logger->error('Не удалось отправить подтверждение верификации канала', [
                    'channelId' => $channel->getId(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
