<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\UseCase\Command\RecordIssuance\RecordIssuanceCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Прямая запись выдачи в журнал ({@see RecordIssuanceCommand}): строки формы становятся неизменяемыми фактами
 * сразу — без черновика, топ-апа и подписи. Материальный инвариант «не меньше нормы» проверяется доменом
 * (на недостаче → AppException). Полнота полей инструктажа (JournalKind) не покрыта этим файлом — журнал
 * структурно недостижим ни для одного оставшегося типа требования (см. JournalKindTest/ProfileComplianceTest).
 */
final class RecordIssuanceTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $bus;
    private ProfileComplianceRepositoryInterface $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $this->bus = $c->get(CommandBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    public function test_records_material_issuance_as_journal_fact(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10 пар

        $this->bus->execute(new RecordIssuanceCommand(
            $p, $r, '2026-06-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair', 'note' => 'Jeta JP711']],
            cardNumber: 'CARD-1',
        ));
        $this->em->clear();

        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(10.0, $pc->heldOf($k), 1e-9, 'выдача сразу в held — факт пишется прямо в журнал');
        self::assertCount(1, $pc->recordsForRequirement($r), 'одна строка журнала на одну выданную позицию');
        self::assertSame('CARD-1', $pc->cardNumber(), 'первая выдача закрепила № карточки');
    }

    public function test_material_issuance_below_norm_throws(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10

        $this->expectException(AppException::class);
        $this->bus->execute(new RecordIssuanceCommand(
            $p, $r, '2026-06-01',
            [['obligationKey' => $k, 'amount' => '5', 'unit' => 'pair']], // на руках 0 + 5 < норма 10
            cardNumber: 'CARD-1',
        ));
    }

    public function test_issuance_without_card_number_throws(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10, 10 достаточно

        $this->expectException(AppException::class); // № карточки не передан и ещё не закреплён ранее
        $this->bus->execute(new RecordIssuanceCommand(
            $p, $r, '2026-06-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']],
        ));
    }
}
