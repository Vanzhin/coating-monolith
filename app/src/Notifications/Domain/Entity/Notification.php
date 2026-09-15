<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Entity;

use App\Notifications\Domain\Event\NotificationCreatedEvent;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Уведомление приложения для пользователя — то, что он видит в разделе «Уведомления».
 * Хранит только текст сообщения и статус прочтения; ничего про каналы/доставку. Владелец —
 * ulid юзера как значение (без ORM-связи на Users\User, чтобы не сцеплять контексты; FK — на
 * уровне БД). Ссылку «вглубь» при необходимости кладём прямо в текст message — отдельного
 * url-поля нет, клик по уведомлению всегда ведёт в раздел уведомлений.
 */
class Notification extends Aggregate
{
    private bool $isRead = false;
    private ?\DateTimeImmutable $readAt = null;

    public function __construct(
        private readonly Uuid $id,
        private readonly string $ownerUlid,
        private readonly string $message,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        if ('' === $message) {
            throw new AppException('Пустое уведомление.');
        }
        // Создание уведомления = намерение доставить: событие поднимается здесь и уедет на шину
        // при сохранении (PublishDomainEventsOnFlushListener), где хендлер сделает рассылку по
        // каналам владельца. Doctrine при гидрации из БД конструктор не зовёт — на загрузке не стрельнёт.
        $this->raise(new NotificationCreatedEvent($id->jsonSerialize()));
    }

    public function markRead(): void
    {
        if ($this->isRead) {
            return;
        }
        $this->isRead = true;
        $this->readAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id->jsonSerialize();
    }

    public function getOwnerUlid(): string
    {
        return $this->ownerUlid;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isRead(): bool
    {
        return $this->isRead;
    }

    public function getReadAt(): ?\DateTimeImmutable
    {
        return $this->readAt;
    }
}
