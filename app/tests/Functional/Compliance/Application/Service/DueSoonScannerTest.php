<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\Service;

use App\Compliance\Application\Service\DueSoonScanner;
use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Service\ComplianceBucketResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Notifications\Domain\Event\DueKind;
use App\Notifications\Domain\Event\NotificationBatch;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Event\EventInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Проход сроков копит дайджесты на человека по позициям «подходит срок/просрочено» и публикует их ОДНОЙ пачкой
 * NotificationBatch (а не N событиями). Сдвигаем «сейчас», чтобы детерминированно получить Overdue. Ловим шину
 * событий спаем — проверяем, что опубликована ровно одна пачка; «всё в норме» → пачка не публикуется.
 */
final class DueSoonScannerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_publishes_single_batch_for_people_with_due_items(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        // выдано 2020-01-01, цикл год → срок 2021 < 2027 → Overdue
        $this->issueCard($profileId, $requirementId, $key, '10', '2020-01-01');

        $bus = $this->capturingBus();
        $count = $this->scannerWith($bus)->scan(new \DateTimeImmutable('2027-07-01'));

        self::assertGreaterThanOrEqual(1, $count);
        self::assertCount(1, $bus->published, 'ровно одна пачка, а не событие на человека');
        self::assertInstanceOf(NotificationBatch::class, $bus->published[0]);
        self::assertCount($count, $bus->published[0]->events(), 'в пачке — дайджест на каждого сотрудника');

        // Содержимое дайджеста (ФИО + позиция + бакет) — то же, что отдавал старый проход по агрегатам: ФИО
        // теперь приходит батчем (GetProfilesByIdsQuery), а не по одному (GetProfileQuery) на сотрудника.
        $digest = $bus->published[0]->events()[0];
        self::assertInstanceOf(ComplianceDueSoon::class, $digest);
        self::assertSame($profileId, $digest->subjectProfileId());
        self::assertSame('Иванов Иван Иванович', $digest->employeeFio());
        self::assertCount(1, $digest->items());
        self::assertSame('Перчатки', $digest->items()[0]->label);
        self::assertSame(DueKind::Overdue, $digest->items()[0]->kind);
    }

    /** Срок за горизонтом DUE_SOON_DAYS (60 дней) не шлётся — bucketFor даёт Ok, а Ok не в списке рассылки. */
    public function test_excludes_items_due_far_beyond_threshold(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        // выдано 2027-01-01, цикл год → срок 2028-01-01 — почти год вперёд от "сейчас" ниже, далеко за 60 дней.
        $this->issueCard($profileId, $requirementId, $key, '10', '2027-01-01');

        $bus = $this->capturingBus();
        $count = $this->scannerWith($bus)->scan(new \DateTimeImmutable('2027-01-10'));

        self::assertSame(0, $count, 'срок почти через год не должен попасть в due-soon скан (горизонт 60 дней)');
        self::assertSame([], $bus->published);
    }

    /** По умолчанию (без сужающих флагов) Missing «не выдано/не выполнено» уходит в проход наравне со сроками. */
    public function test_includes_missing_by_default(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        ['profileId' => $profileId] = $this->enrollCompliance(); // не выдано → Missing

        $bus = $this->capturingBus();
        $count = $this->scannerWith($bus)->scan(new \DateTimeImmutable());

        self::assertGreaterThanOrEqual(1, $count);
        self::assertInstanceOf(NotificationBatch::class, $bus->published[0]);
        $digest = $bus->published[0]->events()[0];
        self::assertInstanceOf(ComplianceDueSoon::class, $digest);
        self::assertSame($profileId, $digest->subjectProfileId());
        self::assertSame('Перчатки', $digest->items()[0]->label);
        self::assertSame(DueKind::Missing, $digest->items()[0]->kind);
    }

    /** Флаг сужает прогон до подмножества бакетов: только Overdue → Missing-позиция не попадает, пачки нет. */
    public function test_bucket_flag_narrows_run_to_subset(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $this->enrollCompliance(); // не выдано → Missing

        $bus = $this->capturingBus();
        $count = $this->scannerWith($bus)->scan(new \DateTimeImmutable(), [ComplianceBucket::Overdue]);

        self::assertSame(0, $count, 'прогон сужен до Overdue — Missing-позиция отфильтрована');
        self::assertSame([], $bus->published);
    }

    private function scannerWith(EventBusInterface $bus): DueSoonScanner
    {
        $c = self::getContainer();

        return new DueSoonScanner(
            $c->get(ObligationProjectionRepositoryInterface::class),
            $c->get(ComplianceBucketResolver::class),
            $c->get(QueryBusInterface::class),
            $bus,
        );
    }

    /** @return EventBusInterface&object{published: list<EventInterface>} */
    private function capturingBus(): EventBusInterface
    {
        return new class implements EventBusInterface {
            /** @var list<EventInterface> */
            public array $published = [];

            public function execute(EventInterface ...$event): void
            {
                foreach ($event as $e) {
                    $this->published[] = $e;
                }
            }
        };
    }
}
