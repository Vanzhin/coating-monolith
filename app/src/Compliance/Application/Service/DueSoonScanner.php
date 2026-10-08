<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Event\ComplianceDueSoon;
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
 * Проход сроков: по каждому сотруднику считает состояние его обязанностей ЕДИНЫМ резолвером
 * ({@see ComplianceStatusResolver::bucketFor()} — то же, что на дашборде) и публикует дайджест НА ЧЕЛОВЕКА
 * (одно событие со списком позиций в статусе «подходит срок»/«просрочено»). Доставку по каналам/подпискам делает
 * NotificationDispatcher.
 *
 * Антифлуд (не слать неизменное повторно) пока НЕ реализован — проход отдаёт текущее состояние на каждый прогон.
 */
final readonly class DueSoonScanner
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $profiles,
        private ComplianceStatusResolver $resolver,
        private QueryBusInterface $queryBus,
        private EventBusInterface $eventBus,
    ) {
    }

    /** @return int число отправленных дайджестов (сотрудников) */
    public function scan(\DateTimeImmutable $now): int
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
                if (ComplianceBucket::Soon === $bucket || ComplianceBucket::Overdue === $bucket) {
                    $items[] = new ComplianceDueItem(
                        $obligation->label(),
                        ComplianceBucket::Overdue === $bucket ? DueKind::Overdue : DueKind::Soon,
                        $obligation->nextDueAt()?->format('d.m.Y') ?? '',
                    );
                }
            }

            if ([] !== $items) {
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
