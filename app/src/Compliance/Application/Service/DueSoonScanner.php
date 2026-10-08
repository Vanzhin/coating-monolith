<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Entity\DueNotificationState;
use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Compliance\Domain\Repository\DueNotificationStateRepositoryInterface;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Service\ComplianceStatusResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\DueKind;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * Проход сроков: раз в день (Scheduler) считает состояние каждой обязанности ЕДИНЫМ резолвером
 * ({@see ComplianceStatusResolver::bucketFor()} — то же, что на дашборде), решает по политике
 * ({@see DueNotificationPolicy}), слать ли, и публикует дайджест НА ЧЕЛОВЕКА (одно событие на сотрудника со
 * списком его позиций Soon/Overdue). Маркер ({@see DueNotificationState}) хранит последнее состояние — повтор
 * неизменного by design не шлётся. Доставку по каналам/подпискам делает NotificationDispatcher.
 */
final readonly class DueSoonScanner
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $profiles,
        private ComplianceStatusResolver $resolver,
        private DueNotificationStateRepositoryInterface $states,
        private DueNotificationPolicy $policy,
        private QueryBusInterface $queryBus,
        private EventBusInterface $eventBus,
    ) {
    }

    /**
     * @param bool $emit false = «прайм»: только засеять маркеры текущим состоянием без рассылки
     *                   (разовый прогон после деплоя, чтобы первый боевой скан не затопил старыми сроками)
     *
     * @return int число отправленных дайджестов (сотрудников)
     */
    public function scan(\DateTimeImmutable $now, bool $emit = true): int
    {
        $sent = 0;
        foreach ($this->profiles->findAllProfileIds() as $profileId) {
            $profileCompliance = $this->profiles->findByProfile($profileId);
            if (null === $profileCompliance) {
                continue;
            }

            $items = [];
            foreach ($profileCompliance->getObligations() as $obligation) {
                $bucket = $this->resolver->bucketFor(
                    $obligation->isActive(),
                    $obligation->lastFulfilledAt(),
                    $obligation->nextDueAt(),
                    $now,
                    $obligation->quantity()?->amount,
                    $obligation->heldQuantity(),
                );
                $key = $obligation->key();
                $state = $this->states->findOne($profileId, $key);
                $notify = $emit && $this->policy->shouldNotify($state?->bucket(), $bucket, $state?->lastNotifiedAt(), $now);

                if ($notify) {
                    $items[] = new ComplianceDueItem(
                        $obligation->label(),
                        ComplianceBucket::Overdue === $bucket ? DueKind::Overdue : DueKind::Soon,
                        $obligation->nextDueAt()?->format('d.m.Y') ?? '',
                    );
                }

                $state ??= DueNotificationState::initial($profileId, $key, $bucket);
                $state->record($bucket, $notify, $now);
                $this->states->save($state);
            }

            if ($emit && [] !== $items) {
                $this->eventBus->execute(new ComplianceDueSoon($profileId, $this->fio($profileId), ...$items));
                ++$sent;
            }
        }

        return $sent;
    }

    private function fio(string $profileId): string
    {
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));
        \assert($result instanceof GetProfileQueryResult);
        $profile = $result->profile;
        if (null === $profile) {
            return '';
        }

        return trim(sprintf('%s %s %s', $profile->lastName, $profile->firstName, $profile->middleName ?? ''));
    }
}
