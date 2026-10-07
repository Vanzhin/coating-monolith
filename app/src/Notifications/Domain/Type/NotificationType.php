<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Type;

/**
 * Каталог типов уведомлений (code-first). Новый тип = новый case + его метаданные здесь.
 * kind: System (принудительный, скрыт из настроек, дефолт «подписан») | Configurable (opt-in, дефолт «нет»).
 */
enum NotificationType: string
{
    case ComplianceDueSoon = 'compliance.due_soon'; // настраиваемый пилот
    case UserActivated = 'user.activated';          // системный (новый пользователь → админу)

    public function kind(): NotificationKind
    {
        return match ($this) {
            self::UserActivated => NotificationKind::System,
            self::ComplianceDueSoon => NotificationKind::Configurable,
        };
    }

    public function category(): NotificationCategory
    {
        return match ($this) {
            self::ComplianceDueSoon => NotificationCategory::Compliance,
            self::UserActivated => NotificationCategory::System,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::ComplianceDueSoon => 'СИЗ: подходит срок выдачи',
            self::UserActivated => 'Новый пользователь',
        };
    }

    /** @return list<NotificationChannel> */
    public function channels(): array
    {
        return match ($this) {
            self::ComplianceDueSoon => [NotificationChannel::Inbox, NotificationChannel::WebPush, NotificationChannel::Email],
            self::UserActivated => [NotificationChannel::Inbox, NotificationChannel::WebPush],
        };
    }

    public function resolver(): ResolverKey
    {
        return match ($this) {
            self::ComplianceDueSoon => ResolverKey::SubjectSupervisors,
            self::UserActivated => ResolverKey::Owner,
        };
    }

    /**
     * Виден в настройках только админам. Для срока выдачи СИЗ — нет: сотрудник подписывается на СВОЙ срок
     * (резолвер отдаёт его событие только ему самому), админ — на все (он в адресатах каждого события).
     */
    public function visibleToAdminOnly(): bool
    {
        return match ($this) {
            self::ComplianceDueSoon => false,
            self::UserActivated => true,
        };
    }

    /** @return list<self> только настраиваемые (для экрана настроек). */
    public static function configurable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $t): bool => !$t->kind()->isSystem()));
    }
}
