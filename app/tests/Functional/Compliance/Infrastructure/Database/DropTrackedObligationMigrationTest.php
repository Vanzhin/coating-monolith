<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Database;

use App\Compliance\Application\Service\DueSoonScanner;
use App\Compliance\Application\UseCase\Query\Dashboard\ComplianceDashboardFilter;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQuery;
use App\Compliance\Application\UseCase\Query\Dashboard\GetPagedComplianceQueryResult;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
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
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guard-тест Task 7 (contract): после дроп-миграции `Version20261105120000` старая `compliance_tracked_obligation`
 * физически отсутствует, сущность `TrackedObligation` удалена — читать её больше НЕЧЕМ. Раньше флип читателей на
 * новую проекцию пинался корёжкой старой таблицы (см. снятые тесты-пины в DueSoonScannerTest/
 * ComplianceDashboardQueryTest/IssueActionTest — Task 4); теперь самой таблицы нет, пинить нечем, остаётся
 * убедиться, что дашборд/карточка/сканер due-soon по-прежнему читают проекцию и не падают на её отсутствии.
 */
final class DropTrackedObligationMigrationTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_old_table_does_not_exist_after_migration(): void
    {
        self::bootKernel();
        $conn = static::getContainer()->get(Connection::class);

        $exists = (bool) $conn->fetchOne("SELECT to_regclass('compliance_tracked_obligation') IS NOT NULL");

        self::assertFalse($exists, 'compliance_tracked_obligation снесена миграцией Version20261105120000 (Task 7)');
    }

    public function test_dashboard_card_and_scanner_read_projection_without_old_table(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        $this->issueCard($profileId, $requirementId, $key, '10', '2020-01-01'); // выдано давно → Overdue

        $queryBus = static::getContainer()->get(QueryBusInterface::class);

        // Карточка (obligation-строки /issue, read-side GetProfileCompliance) — читает только проекцию.
        /** @var GetProfileComplianceQueryResult $cardResult */
        $cardResult = $queryBus->execute(new GetProfileComplianceQuery($profileId));
        self::assertNotNull($cardResult->compliance);
        self::assertSame(ComplianceBucket::Overdue->value, $cardResult->compliance->obligations[0]->bucket);

        // Дашборд (список) — та же read-модель.
        /** @var GetPagedComplianceQueryResult $pagedResult */
        $pagedResult = $queryBus->execute(new GetPagedComplianceQuery(new ComplianceDashboardFilter()));
        $row = null;
        foreach ($pagedResult->people as $candidate) {
            if ($candidate->profileId === $profileId) {
                $row = $candidate;
                break;
            }
        }
        self::assertNotNull($row, 'человек есть в списке дашборда');
        self::assertSame(ComplianceBucket::Overdue->value, $row->worstBucket);

        // Сканер due-soon — тот же источник.
        $bus = $this->capturingBus();
        $scanner = new DueSoonScanner(
            static::getContainer()->get(ObligationProjectionRepositoryInterface::class),
            static::getContainer()->get(ComplianceBucketResolver::class),
            $queryBus,
            $bus,
        );
        $count = $scanner->scan(new \DateTimeImmutable('2027-07-01'));

        self::assertSame(1, $count);
        self::assertInstanceOf(NotificationBatch::class, $bus->published[0]);
        $digest = $bus->published[0]->events()[0];
        self::assertInstanceOf(ComplianceDueSoon::class, $digest);
        self::assertSame(DueKind::Overdue, $digest->items()[0]->kind);
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
