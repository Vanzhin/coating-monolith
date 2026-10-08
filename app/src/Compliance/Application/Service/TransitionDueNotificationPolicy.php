<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Type\ComplianceBucket;

/**
 * БАЗОВАЯ политика антифлуда: уведомляем ТОЛЬКО при ухудшении состояния до проблемного по сроку
 * (Ok→Soon, Soon→Overdue, Ok→Overdue) — одно сообщение-«новость», без ежедневных повторов. Неизменное
 * состояние молчит. Текущее состояние обязанности видно на дашборде-светофоре.
 *
 * Напоминания о стоянии (раз/нед) и эскалация по бездействию (сотрудник→начальник→админ) — расширение ЭТОЙ
 * точки (другая реализация {@see DueNotificationPolicy}); конкретная политика уведомлений ещё обсуждается,
 * поэтому пока здесь только неспорное ядро «шлём на ухудшении».
 */
final class TransitionDueNotificationPolicy implements DueNotificationPolicy
{
    public function shouldNotify(?ComplianceBucket $previous, ComplianceBucket $current, ?\DateTimeImmutable $lastNotifiedAt, \DateTimeImmutable $now): bool
    {
        $currentRank = $this->dueRank($current);
        if (0 === $currentRank) {
            return false; // не Soon/Overdue — про Ok/Missing этим дайджестом сроков не уведомляем
        }

        return $currentRank > $this->dueRank($previous); // только на ухудшении ПО СРОКУ
    }

    /**
     * Ранг по оси СРОКА, а не по дашборд-{@see ComplianceBucket::severity()} (там Missing=3 главенствует как
     * «разрыв оформления»). Здесь Missing и Ok — одинаковый базовый 0: выдача карточки с близким/прошедшим
     * сроком (Missing→Soon/Overdue) обязана уведомить, а не считаться «улучшением».
     */
    private function dueRank(?ComplianceBucket $bucket): int
    {
        return match ($bucket) {
            ComplianceBucket::Soon => 1,
            ComplianceBucket::Overdue => 2,
            default => 0,
        };
    }
}
