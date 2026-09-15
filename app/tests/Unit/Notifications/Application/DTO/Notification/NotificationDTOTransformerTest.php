<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application\DTO\Notification;

use App\Notifications\Application\DTO\Notification\NotificationDTOTransformer;
use App\Notifications\Domain\Entity\Notification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NotificationDTOTransformerTest extends TestCase
{
    public function test_from_entity_maps_fields(): void
    {
        $transformer = new NotificationDTOTransformer();
        $createdAt = new \DateTimeImmutable('2026-09-15 10:00:00');
        $notification = new Notification(Uuid::v7(), '01JQABCDEFGHJKMNPQRSTVWXYZ', 'Покрытие обновлено', $createdAt);

        $dto = $transformer->fromEntity($notification);

        self::assertSame($notification->getId(), $dto->id);
        self::assertSame('Покрытие обновлено', $dto->message);
        self::assertSame($createdAt, $dto->createdAt);
        self::assertFalse($dto->isRead);
    }

    public function test_from_entity_list_preserves_order(): void
    {
        $transformer = new NotificationDTOTransformer();

        $list = $transformer->fromEntityList([
            new Notification(Uuid::v7(), 'o', 'первое', new \DateTimeImmutable()),
            new Notification(Uuid::v7(), 'o', 'второе', new \DateTimeImmutable()),
        ]);

        self::assertCount(2, $list);
        self::assertSame('первое', $list[0]->message);
        self::assertSame('второе', $list[1]->message);
    }
}
