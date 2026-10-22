<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Compliance\Domain\ReadModel\ObligationProjection;
use App\Compliance\Domain\Repository\ObligationProjectionFilter;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Service\ComplianceBucketResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\DueKind;
use App\Notifications\Domain\Event\NotificationBatch;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetProfilesByIds\GetProfilesByIdsQueryResult;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;

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

    /**
     * Состояния, о которых проходчик уведомляет — ЕДИНЫЙ источник политики рассылки. Убрать строку =
     * исключить состояние (и его флаг у команды) навсегда. Ok сюда не входит никогда. Прогон можно сузить
     * до подмножества этого списка ({@see scan()} аргументом), но расширить за его пределы — нет.
     *
     * @var list<ComplianceBucket>
     */
    public const NOTIFIABLE_BUCKETS = [
        ComplianceBucket::Overdue,
        ComplianceBucket::Soon,
        ComplianceBucket::Missing,
    ];

    public function __construct(
        private ObligationProjectionRepositoryInterface $projections,
        private ComplianceBucketResolver $resolver,
        private QueryBusInterface $queryBus,
        private EventBusInterface $eventBus,
    ) {
    }

    /**
     * @param list<ComplianceBucket>|null $onlyBuckets подмножество бакетов для этого прогона; null — весь
     *                                                 {@see self::NOTIFIABLE_BUCKETS} (поведение по умолчанию)
     *
     * @return int число дайджестов (сотрудников) в опубликованной пачке
     */
    public function scan(\DateTimeImmutable $now, ?array $onlyBuckets = null): int
    {
        $buckets = $onlyBuckets ?? self::NOTIFIABLE_BUCKETS;

        $obligationsByProfile = [];
        foreach ($this->allObligations() as $obligation) {
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
                if (\in_array($bucket, $buckets, true)) {
                    $items[] = new ComplianceDueItem(
                        $obligation->label(),
                        $this->dueKindOf($bucket),
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
     * Все строки проекции постранично. SQL-предфильтра по состоянию (active/dueBefore) НЕТ сознательно:
     * «кого слать» решает ОДНО место — список {@see self::NOTIFIABLE_BUCKETS} поверх посчитанного
     * {@see ComplianceBucketResolver::bucketFor()}, а не вторая копия правила в запросе. Это лёгкая
     * read-модель (не агрегаты), читается дёшево.
     *
     * @return list<ObligationProjection>
     */
    private function allObligations(): array
    {
        $rows = [];
        $page = 1;
        do {
            $result = $this->projections->findByFilter(new ObligationProjectionFilter(Pager::fromPage($page, self::BATCH_SIZE)));
            /** @var list<ObligationProjection> $items */
            $items = $result->items;
            $rows = [...$rows, ...$items];
            ++$page;
        } while (count($rows) < $result->total);

        return $rows;
    }

    /** Представление уведомляемого бакета в строке дайджеста — тотальный перевод, не политика (Ok недостижим). */
    private function dueKindOf(ComplianceBucket $bucket): DueKind
    {
        return match ($bucket) {
            ComplianceBucket::Soon => DueKind::Soon,
            ComplianceBucket::Overdue => DueKind::Overdue,
            ComplianceBucket::Missing => DueKind::Missing,
            ComplianceBucket::Ok => throw new AppException('Бакет Ok не уведомляемый.'),
        };
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
