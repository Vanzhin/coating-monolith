<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Compliance\Domain\Repository\ObligationProjectionFilter;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Service\ComplianceBucketResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection;
use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\DueKind;
use App\Notifications\Domain\Event\NotificationBatch;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQueryResult;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Проход сроков: по каждому сотруднику считает состояние его обязанностей ЕДИНЫМ резолвером
 * ({@see ComplianceBucketResolver::bucketFor()} — то же, что на дашборде) и копит дайджест НА ЧЕЛОВЕКА
 * (одно событие со списком позиций «подходит срок»/«просрочено»). В конце прохода публикует всё ОДНОЙ пачкой
 * NotificationBatch — слой уведомлений схлопывает её по получателям (админ получает одну сводку, не N).
 * Нечего слать (все в норме) — пачка не публикуется.
 *
 * Антифлуд (не слать неизменное повторно) пока НЕ реализован — проход отдаёт текущее состояние на каждый прогон.
 */
final readonly class DueSoonScanner
{
    /** Размер страницы прохода по проекции — компромисс между числом запросов и памятью (масштаб — сотрудники). */
    private const BATCH_SIZE = 500;

    public function __construct(
        private ObligationProjectionRepositoryInterface $projections,
        private ComplianceBucketResolver $resolver,
        private QueryBusInterface $queryBus,
        private EventBusInterface $eventBus,
    ) {
    }

    /** @return int число дайджестов (сотрудников) в опубликованной пачке */
    public function scan(\DateTimeImmutable $now): int
    {
        // Порог совпадает с окном резолвера (DUE_SOON_DAYS) — SQL отбирает ровно тех кандидатов, которых
        // bucketFor() ниже мог бы признать Soon/Overdue (сам bucketFor решает финально — held<норма всё ещё
        // валит в Missing, несмотря на близкий срок).
        $threshold = $now->modify('+'.ComplianceBucketResolver::DUE_SOON_DAYS.' days');

        $obligationsByProfile = [];
        foreach ($this->dueSoonCandidates($threshold) as $obligation) {
            $obligationsByProfile[$obligation->profileId()][] = $obligation;
        }

        if ([] === $obligationsByProfile) {
            return 0;
        }

        $fioByProfileId = $this->fioByProfileIds(array_keys($obligationsByProfile));

        $digests = [];
        foreach ($obligationsByProfile as $profileId => $obligations) {
            $items = [];
            foreach ($obligations as $obligation) {
                $bucket = $this->resolver->bucketFor($obligation, $now);
                if (ComplianceBucket::Soon === $bucket || ComplianceBucket::Overdue === $bucket) {
                    $items[] = new ComplianceDueItem(
                        $obligation->label(),
                        ComplianceBucket::Overdue === $bucket ? DueKind::Overdue : DueKind::Soon,
                        $obligation->nextDueAt()?->format('d.m.Y') ?? '',
                    );
                }
            }

            if ([] !== $items) {
                $digests[] = new ComplianceDueSoon((string) $profileId, $fioByProfileId[$profileId] ?? '', ...$items);
            }
        }

        if ([] === $digests) {
            return 0;
        }

        $this->eventBus->execute(new NotificationBatch(...$digests));

        return \count($digests);
    }

    /**
     * Активные строки проекции с датой следующего срока не позже $threshold — весь набор, листая страницы
     * {@see ObligationProjectionRepositoryInterface::findByFilter} (единственный способ фильтровать по
     * active+dueBefore без обхода профилей поимённо — кандидатов на due-soon заранее не знаем).
     *
     * @return list<ObligationProjection>
     */
    private function dueSoonCandidates(\DateTimeImmutable $threshold): array
    {
        $rows = [];
        $page = 1;
        do {
            $result = $this->projections->findByFilter(new ObligationProjectionFilter(
                Pager::fromPage($page, self::BATCH_SIZE),
                active: true,
                dueBefore: $threshold,
            ));
            /** @var list<ObligationProjection> $items */
            $items = $result->items;
            $rows = [...$rows, ...$items];
            ++$page;
        } while (count($rows) < $result->total);

        return $rows;
    }

    /**
     * ФИО батчем на всех сотрудников дайджеста (не N+1 запросов по одному на человека).
     *
     * @param list<string> $profileIds
     *
     * @return array<string, string> profileId => ФИО
     */
    private function fioByProfileIds(array $profileIds): array
    {
        $result = $this->queryBus->execute(new GetProfilesByIdsQuery(new StringCollection(...$profileIds)));
        \assert($result instanceof GetProfilesByIdsQueryResult);

        $fio = [];
        foreach ($result->profiles as $profile) {
            $fio[$profile->id] = $profile->fio();
        }

        return $fio;
    }
}
