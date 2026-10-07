<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\EventHandler;

use App\Notifications\Domain\Event\UserActivatedNotification;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Users\Domain\Event\UserActivatedEvent;
use App\Users\Domain\Repository\UserRepositoryInterface;

/**
 * Пользователь стал активным (подтвердил первый канал) → уведомляем администратора
 * (email из ADMIN_NOTIFY_EMAIL). Публикуем уведомляющее событие UserActivatedNotification (системный
 * тип, Owner=админ), дальше единый NotificationDispatcher доставит по каналам. Выполняется асинхронно.
 */
readonly class UserActivatedEventHandler implements EventHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private EventBusInterface $eventBus,
        private string $adminNotifyEmail,
    ) {
    }

    public function __invoke(UserActivatedEvent $event): void
    {
        // Событие публикуется ДО коммита транзакции активации, а строка пользователя существует ещё
        // с регистрации. Поэтому проверяем именно то состояние, которое утверждает событие — is_active,
        // а не факт существования строки: если активация ещё не видна (in-flight) — бросаем, messenger
        // повторит; окончательный откат исчерпает ретраи и уйдёт в failed-транспорт, не породив
        // призрачного уведомления админу.
        $user = $this->userRepository->getByUlid($event->userId);
        if (null === $user || !$user->isActive()) {
            throw new \RuntimeException(sprintf('Активация пользователя `%s` ещё не видна в БД.', $event->userId));
        }

        $admin = $this->userRepository->getByEmail($this->adminNotifyEmail);
        if (null === $admin || $admin->getUlid() === $user->getUlid()) {
            // Админ не настроен/не найден либо это его собственная активация — молча выходим.
            return;
        }

        $this->eventBus->execute(new UserActivatedNotification($admin->getUlid(), $user->getEmail()->getValue()));
    }
}
