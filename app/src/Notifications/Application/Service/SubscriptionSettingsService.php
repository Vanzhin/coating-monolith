<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service;

use App\Notifications\Application\Service\Settings\ChannelSetting;
use App\Notifications\Application\Service\Settings\TypeSetting;
use App\Notifications\Domain\Entity\Subscription;
use App\Notifications\Domain\Repository\SubscriptionRepositoryInterface;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;

/** Экран настроек подписок: показывает настраиваемые типы с галочками каналов и сохраняет выбор. */
final readonly class SubscriptionSettingsService
{
    public function __construct(private SubscriptionRepositoryInterface $subscriptions)
    {
    }

    /** @return list<TypeSetting> видимые пользователю настраиваемые типы с текущим состоянием каналов */
    public function configurableTypesForUser(string $userUlid, bool $isAdmin): array
    {
        $enabled = $this->enabledKeys($userUlid);
        $result = [];
        foreach (NotificationType::configurable() as $type) {
            if ($type->visibleToAdminOnly() && !$isAdmin) {
                continue;
            }
            $channels = [];
            foreach ($type->channels() as $channel) {
                $channels[] = new ChannelSetting($channel, \in_array($type->value.'|'.$channel->value, $enabled, true));
            }
            $result[] = new TypeSetting($type, $channels);
        }

        return $result;
    }

    /**
     * Сохранить подписку ОДНОГО события (у каждого события своя кнопка): по каждому каналу типа выставить
     * подписку по галочке (set-семантика; снятые все каналы → событие выключено). Тип, недоступный этому
     * пользователю (системный/скрытый), тихо игнорируем.
     *
     * @param array<string, mixed> $postedChannels channels[channelValue] = '1' для включённых
     */
    public function saveType(string $userUlid, bool $isAdmin, NotificationType $type, array $postedChannels): void
    {
        if (!$this->isConfigurableForUser($type, $isAdmin)) {
            return;
        }
        foreach ($type->channels() as $channel) {
            $this->toggle($userUlid, $type, $channel, isset($postedChannels[$channel->value]));
        }
    }

    private function isConfigurableForUser(NotificationType $type, bool $isAdmin): bool
    {
        if ($type->kind()->isSystem()) {
            return false;
        }

        return $isAdmin || !$type->visibleToAdminOnly();
    }

    public function toggle(string $userUlid, NotificationType $type, NotificationChannel $channel, bool $enabled): void
    {
        if ($type->kind()->isSystem()) {
            throw new AppException('Системные уведомления нельзя отключить.');
        }
        $existing = $this->findRow($userUlid, $type, $channel);
        if (null !== $existing) {
            $existing->setEnabled($enabled);
            $this->subscriptions->save($existing);

            return;
        }
        $this->subscriptions->save(new Subscription(UuidService::generateUuid(), $userUlid, $type, $channel, $enabled));
    }

    /** @return list<string> ключи «type|channel» включённых подписок */
    private function enabledKeys(string $userUlid): array
    {
        $keys = [];
        foreach ($this->subscriptions->findForUser($userUlid) as $sub) {
            if ($sub->isEnabled()) {
                $keys[] = $sub->getType()->value.'|'.$sub->getChannel()->value;
            }
        }

        return $keys;
    }

    private function findRow(string $userUlid, NotificationType $type, NotificationChannel $channel): ?Subscription
    {
        foreach ($this->subscriptions->findForUser($userUlid) as $sub) {
            if ($sub->getType() === $type && $sub->getChannel() === $channel) {
                return $sub;
            }
        }

        return null;
    }
}
