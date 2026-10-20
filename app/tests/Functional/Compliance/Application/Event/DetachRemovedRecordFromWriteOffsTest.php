<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\Event;

use App\Compliance\Application\Event\DetachRemovedRecordFromWriteOffsHandler;
use App\Compliance\Application\UseCase\Command\AddToWriteOffDraft\AddToWriteOffDraftCommand;
use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Domain\Event\FulfillmentRecordRemoved;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Каскад удаления факта выдачи на черновики акта списания ({@see FulfillmentRecordRemoved} →
 * {@see DetachRemovedRecordFromWriteOffsHandler}) — защитный, forward-looking: прод-пути удаления факта пока
 * нет (нет UI/команды), поэтому дёргаем хендлер напрямую, как RecomputeOnWriteOffActSignedHandler в
 * {@see \App\Tests\Functional\Compliance\Application\UseCase\WriteOffFlowTest}.
 */
final class DetachRemovedRecordFromWriteOffsTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ProfileComplianceRepositoryInterface $repo;
    private WriteOffActRepositoryInterface $writeOffActRepo;
    private DetachRemovedRecordFromWriteOffsHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->writeOffActRepo = $c->get(WriteOffActRepositoryInterface::class);
        $this->handler = $c->get(DetachRemovedRecordFromWriteOffsHandler::class);
        $this->authenticateAsSystem();
    }

    public function test_removes_line_from_draft_keeping_other_lines(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $kGloves] = $this->enrollCompliance('Перчатки', null, [
            ['label' => 'Нарукавники', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '5', 'unit' => 'pair', 'basis' => 'п.7'],
        ]);
        $kSleeves = $this->obligationKeyFor($r, 'Нарукавники');
        $this->issueCard($p, $r, $kGloves); // held 10
        $this->issueCard($p, $r, $kSleeves, '5'); // held 5

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, null));
        $this->reload();

        $org = $this->organizationIdOf($p);
        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act);
        self::assertCount(2, $act->lines(), 'в черновике обе позиции');
        $recordIds = array_map(static fn ($line): string => $line->recordId(), $act->lines());
        $removedRecordId = $recordIds[0];
        $keptRecordId = $recordIds[1];
        $actId = $act->getId();

        ($this->handler)(new FulfillmentRecordRemoved($p, $removedRecordId));
        $this->reload();

        $afterCascade = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($afterCascade, 'в акте осталась вторая строка — акт не удалён');
        self::assertCount(1, $afterCascade->lines());
        self::assertSame($keptRecordId, $afterCascade->lines()[0]->recordId(), 'удалённая строка снята, оставшаяся не тронута');
    }

    public function test_removes_draft_entirely_when_last_line_detached(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, [$k]));
        $this->reload();

        $org = $this->organizationIdOf($p);
        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act);
        self::assertCount(1, $act->lines());
        $recordId = $act->lines()[0]->recordId();
        $actId = $act->getId();

        ($this->handler)(new FulfillmentRecordRemoved($p, $recordId));
        $this->reload();

        self::assertNull($this->writeOffActRepo->findById($actId), 'пустой черновик (последняя строка снята) удалён целиком');
    }

    public function test_signed_act_is_not_touched_by_cascade(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->repo->findByProfile($p)?->getRecords()[0]->getId();
        self::assertNotNull($recordId);

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, [$k]));
        $this->reload();
        $org = $this->organizationIdOf($p);
        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act);
        $actId = $act->getId();

        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => 10.0, 'reason' => 'physical_wear']]));
        $this->reload();
        $this->commandBus->execute(new SignWriteOffActCommand($actId, [['name' => 'Петров П. П.', 'position' => 'Инженер']], '2026-08-10', $this->stageComplianceScan()));
        $this->reload();

        $signedAct = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($signedAct);
        self::assertTrue($signedAct->isSigned());

        ($this->handler)(new FulfillmentRecordRemoved($p, $recordId));
        $this->reload();

        $untouched = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($untouched, 'подписанный акт не удалён каскадом');
        self::assertTrue($untouched->isSigned());
        self::assertCount(1, $untouched->lines(), 'строка подписанного акта не снята — заморожен');
    }

    private function organizationIdOf(string $profileId): string
    {
        /** @var GetProfileQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));
        self::assertNotNull($result->profile);

        return $result->profile->organizationId;
    }

    private function reload(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
