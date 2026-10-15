<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\DTO\ObligationHistory\ObligationHistoryEventDTO;
use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\StartWriteOffAct\StartWriteOffActCommand;
use App\Compliance\Application\UseCase\Query\GetObligationHistory\GetObligationHistoryQuery;
use App\Compliance\Application\UseCase\Query\GetObligationHistory\GetObligationHistoryQueryResult;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Лента истории ОДНОЙ позиции (журнал выдачи + журнал/акты списания): карточка на странице выдачи грузит
 * её лениво через {@see \App\Compliance\Infrastructure\Controller\Fulfillment\ObligationHistoryAction}, этот
 * тест — на сам read-model (без HTTP), чтобы проверить сборку и хронологию детально.
 */
final class GetObligationHistoryQueryTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_empty_when_nothing_issued_yet(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $result = $this->history($p, $r, $k);

        self::assertSame([], $result->events);
    }

    public function test_single_issuance_event(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k, amount: '10', date: '2026-06-01');

        $result = $this->history($p, $r, $k);

        self::assertCount(1, $result->events);
        $event = $result->events[0];
        self::assertSame(ObligationHistoryEventDTO::TYPE_ISSUED, $event->type);
        self::assertSame('2026-06-01', $event->date);
        self::assertStringContainsString('10', (string) $event->quantityLabel);
        self::assertNull($event->reason, 'причина — только у списания');
    }

    public function test_draft_write_off_excluded_from_history(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k, amount: '10', date: '2026-06-01');
        $recordId = $this->firstRecordId($p);

        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand(
            $p, $actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']], actDate: '2026-07-01',
        ));
        $this->reload();

        $result = $this->history($p, $r, $k);

        // История — только факты (выдано/списано). Неподписанный черновик акта в ленту не попадает.
        self::assertCount(1, $result->events, 'в ленте только выдача — черновик списания не показываем');
        self::assertSame(ObligationHistoryEventDTO::TYPE_ISSUED, $result->events[0]->type);
    }

    public function test_signed_write_off_event_chronological_after_issuance_and_carries_act_number(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k, amount: '10', date: '2026-06-01');
        $recordId = $this->firstRecordId($p);

        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand(
            $p, $actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']], actDate: '2026-08-10',
        ));
        $this->commandBus->execute(new SignWriteOffActCommand(
            $p, $actId, [['name' => 'Петров П. П.', 'position' => 'Инженер']], '39', '2026-08-10', $this->stageComplianceScan(),
        ));
        $this->reload();

        $result = $this->history($p, $r, $k);

        self::assertCount(2, $result->events);
        self::assertSame(ObligationHistoryEventDTO::TYPE_ISSUED, $result->events[0]->type);
        self::assertSame('2026-06-01', $result->events[0]->date);
        $writeOff = $result->events[1];
        self::assertSame(ObligationHistoryEventDTO::TYPE_WRITE_OFF, $writeOff->type);
        self::assertSame('2026-08-10', $writeOff->date, 'хронологически после выдачи — позже по дате акта');
        self::assertSame('39', $writeOff->actNumber);
    }

    public function test_history_is_scoped_to_its_own_obligation_key_not_to_whole_requirement(): void
    {
        // Норма с ДВУМЯ позициями — обе выданы, историю каждой смотрим по отдельности: факты соседней
        // позиции (другого obligationKey, тот же requirementId) не должны утекать в чужую ленту.
        // Unit совпадает с «Перчатки» (pair) — {@see EnrollsComplianceTrait::issueCard} выдаёт фиксированной
        // единицей, несовпадение упёрлось бы в доменный инвариант {@see ProfileCompliance::assertIssuable}.
        ['profileId' => $p, 'requirementId' => $r, 'key' => $kGloves] = $this->enrollCompliance('Перчатки', extraItems: [
            ['label' => 'Очки защитные', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1', 'unit' => 'pair', 'basis' => 'п.6'],
        ]);
        $kGoggles = TrackedObligation::keyOf($r, 'Очки защитные');
        $this->issueCard($p, $r, $kGloves, amount: '10', date: '2026-06-01');
        $this->issueCard($p, $r, $kGoggles, amount: '1', date: '2026-06-02');

        $glovesHistory = $this->history($p, $r, $kGloves);
        $gogglesHistory = $this->history($p, $r, $kGoggles);

        self::assertCount(1, $glovesHistory->events);
        self::assertSame('2026-06-01', $glovesHistory->events[0]->date);
        self::assertCount(1, $gogglesHistory->events);
        self::assertSame('2026-06-02', $gogglesHistory->events[0]->date);
    }

    private function history(string $profileId, string $requirementId, string $obligationKey): GetObligationHistoryQueryResult
    {
        $result = $this->queryBus->execute(new GetObligationHistoryQuery($profileId, $requirementId, $obligationKey));
        \assert($result instanceof GetObligationHistoryQueryResult);

        return $result;
    }

    private function startWriteOff(string $profileId, string $requirementId): string
    {
        $this->commandBus->execute(new StartWriteOffActCommand($profileId, $requirementId));
        $this->reload();
        $pc = static::getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($profileId);
        self::assertNotNull($pc);
        $act = $pc->openWriteOffDraftFor($requirementId);
        self::assertNotNull($act);

        return $act->getId();
    }

    private function firstRecordId(string $profileId): string
    {
        $this->reload();
        $pc = static::getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($profileId);
        self::assertNotNull($pc);

        return $pc->getRecords()[0]->getId();
    }

    private function reload(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
