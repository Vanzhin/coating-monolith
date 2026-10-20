<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Event\RecomputeOnWriteOffActSignedHandler;
use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\AddToWriteOffDraft\AddToWriteOffDraftCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Event\WriteOffActSigned;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommandResult;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Флоу списания на самостоятельном кросс-персон агрегате {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct}:
 * наполнение корзины с карточки выдачи ({@see AddToWriteOffDraftCommand}, покрыто {@see AddToWriteOffDraftTest})
 * создаёт/открывает черновик; на его странице указывают количество+причину (SaveWriteOffAct) БЕЗ эффекта;
 * оформление (комиссия+№+скан) замораживает И гасит факты фан-аутом ВСЕМ людям акта (SignWriteOffAct) → на
 * руках падает (дефицит виден прямо в журнале, без карточек-актов).
 */
final class WriteOffFlowTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ProfileComplianceRepositoryInterface $repo;
    private WriteOffActRepositoryInterface $writeOffActRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->writeOffActRepo = $c->get(WriteOffActRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_save_portion_without_effect(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // действующая карточка

        $recordId = $this->firstRecordId($p);
        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));

        $this->reload();
        $act = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($act);
        self::assertTrue($act->isDraft());
        self::assertCount(1, $act->lines());
        self::assertEqualsWithDelta(10.0, $this->repo->findByProfile($p)?->heldOf($k), 1e-9, 'эффекта нет — на руках всё ещё норма до оформления акта');
    }

    public function test_partial_write_off_leaves_deficit(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10 (материальная)
        $this->issueCard($p, $r, $k); // выдано 10 ≥ нормы
        $recordId = $this->firstRecordId($p);

        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));
        $this->signWriteOff($actId, $r, [$p]);

        $this->reload();
        $act = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($act);
        self::assertTrue($act->isSigned());
        self::assertEqualsWithDelta(9.0, $this->repo->findByProfile($p)?->heldOf($k), 1e-9, 'списали 1 из 10 → на руках 9 < норма 10 (дефицит виден в журнале)');
    }

    /**
     * Регрессия: после частичного списания, оставляющего «на руках» меньше нормы, read-model (тот же путь, что
     * {@see \App\Compliance\Infrastructure\Controller\Fulfillment\IssueAction} использует для фильтрации позиций
     * к выдаче) НЕ должен показывать Green — иначе дефицитная позиция выпадает из формы повторной выдачи.
     */
    public function test_partial_write_off_then_read_model_status_is_not_green_for_deficit(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);

        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));
        $this->signWriteOff($actId, $r, [$p]);

        $this->reload();
        /** @var GetProfileComplianceQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileComplianceQuery($p));
        self::assertNotNull($result->compliance);
        $row = null;
        foreach ($result->compliance->obligations as $obligation) {
            if ($obligation->key === $k) {
                $row = $obligation;
                break;
            }
        }
        self::assertNotNull($row, 'обязанность должна остаться в проекции');
        self::assertNotSame(
            ComplianceStatus::Green->value,
            $row->status,
            'на руках 9 < норма 10 — read-model не должен светить Green, иначе IssueAction отфильтрует дефицитную позицию',
        );
    }

    public function test_sign_write_off_act_frees_position(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $held = $this->repo->findByProfile($p)?->heldOf($k) ?? 0.0;

        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => $held, 'reason' => 'physical_wear']]));
        $this->signWriteOff($actId, $r, [$p]);

        $this->reload();
        $act = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($act);
        self::assertTrue($act->isSigned());
        self::assertMatchesRegularExpression('/^\d+$/', (string) $act->actNumber(), 'номер акта — сквозной авто-инкремент, присвоен при подписании');
        self::assertNotNull($act->commission());
        self::assertEqualsWithDelta(0.0, $this->repo->findByProfile($p)?->heldOf($k), 1e-9, 'списали всё → на руках 0 (дефицит виден в журнале)');
    }

    public function test_sign_recompute_is_idempotent(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));
        $this->signWriteOff($actId, $r, [$p]); // подпись + первая обработка события

        // Повторная обработка события (воркер мог переобработать) не должна задваивать эффект.
        (static::getContainer()->get(RecomputeOnWriteOffActSignedHandler::class))(new WriteOffActSigned($r, [$p]));
        $this->reload();

        self::assertEqualsWithDelta(9.0, $this->repo->findByProfile($p)?->heldOf($k), 1e-9, 'количество погашено ровно один раз в синхронной подписи — повтор события его не трогает');
    }

    public function test_sign_write_off_without_scan_throws(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));
        $this->reload();

        $this->expectException(AppException::class); // оформление акта списания без скана — инвариант домена, не тихое сохранение
        $this->commandBus->execute(new SignWriteOffActCommand($actId, [['name' => 'Петров П. П.', 'position' => 'Инженер']], '2026-03-01', null));
    }

    public function test_draft_gets_auto_number_at_creation_and_keeps_it_after_signing(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));
        $this->reload();

        $draft = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($draft);
        self::assertMatchesRegularExpression('/^\d+$/', (string) $draft->actNumber(), 'номер присвоен уже на черновике (при создании) — чтобы печатать акт до подписи');
        $numberOnDraft = $draft->actNumber();

        $this->signWriteOff($actId, $r, [$p]);
        $this->reload();

        $signed = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($signed);
        self::assertSame($numberOnDraft, $signed->actNumber(), 'подпись номер не меняет — он readonly с момента создания');
    }

    public function test_act_numbers_are_sequential_across_drafts(): void
    {
        // Два разных черновика (разные требования×организации) получают последовательные сквозные номера ПРИ СОЗДАНИИ.
        // Значения относительные: sequence в Postgres не откатывается (в т.ч. под DAMA).
        ['profileId' => $p1, 'requirementId' => $r1, 'key' => $k1] = $this->enrollCompliance();
        $this->issueCard($p1, $r1, $k1);
        $act1 = $this->writeOffActRepo->findById($this->startWriteOff($p1, $r1));

        ['profileId' => $p2, 'requirementId' => $r2, 'key' => $k2] = $this->enrollCompliance();
        $this->issueCard($p2, $r2, $k2);
        $act2 = $this->writeOffActRepo->findById($this->startWriteOff($p2, $r2));

        self::assertNotNull($act1);
        self::assertNotNull($act2);
        self::assertSame((int) $act1->actNumber() + 1, (int) $act2->actNumber(), 'номера черновиков идут подряд — сквозной счётчик при создании');
    }

    public function test_save_portion_exceeding_held_throws(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // на руках 10
        $recordId = $this->firstRecordId($p);
        $actId = $this->startWriteOff($p, $r);

        $this->expectException(AppException::class); // 11 > held(10) — бэк источник истины, не фронт-валидация
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [['recordId' => $recordId, 'quantity' => 11.0, 'reason' => 'physical_wear']]));
    }

    /**
     * Кросс-персон: один акт (одно требование × одна организация) несёт позиции ДВУХ разных людей — перчатки
     * человека A (held 10) и каска человека B (held 1). Подпись должна погасить фактам ОБОИХ, не только
     * первого по порядку строк, одной транзакцией; пересчёт — у обоих.
     */
    public function test_cross_person_sign_quenches_all_profiles_in_fan_out(): void
    {
        ['positionId' => $pos, 'requirementId' => $r] = $this->createSharedRequirementWithTwoItems();
        $kGloves = $this->obligationKeyFor($r, 'Перчатки');
        $kHelmet = $this->obligationKeyFor($r, 'Каска защитная');

        ['profileId' => $p1, 'organizationId' => $org, 'departmentId' => $dept] = $this->enrollProfileForPosition($pos);
        $p2 = $this->enrollSecondProfileInOrganization($org, $dept, $pos);

        $this->issueCard($p1, $r, $kGloves); // held 10
        $this->issueCard($p2, $r, $kHelmet, '1'); // held 1

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p1, $r, [$kGloves]));
        $this->reload();
        $this->commandBus->execute(new AddToWriteOffDraftCommand($p2, $r, [$kHelmet]));
        $this->reload();

        $act = $this->writeOffActRepo->findOpenDraft($r, $org);
        self::assertNotNull($act, 'оба человека одной организации попали в один черновик');
        self::assertSame([$p1, $p2], $act->profileIds());
        $actId = $act->getId();

        $p1RecordId = $this->firstRecordId($p1);
        $p2RecordId = $this->firstRecordId($p2);
        $this->commandBus->execute(new SaveWriteOffActCommand($actId, [
            ['recordId' => $p1RecordId, 'quantity' => 10.0, 'reason' => 'physical_wear'],
            ['recordId' => $p2RecordId, 'quantity' => 1.0, 'reason' => 'physical_wear'],
        ]));
        $this->signWriteOff($actId, $r, [$p1, $p2]);

        $this->reload();
        self::assertEqualsWithDelta(0.0, $this->repo->findByProfile($p1)?->heldOf($kGloves), 1e-9, 'человек A: перчатки погашены полностью');
        self::assertEqualsWithDelta(0.0, $this->repo->findByProfile($p2)?->heldOf($kHelmet), 1e-9, 'человек B: каска погашена полностью');
        $signedAct = $this->writeOffActRepo->findById($actId);
        self::assertNotNull($signedAct);
        self::assertTrue($signedAct->isSigned());
    }

    /** Наполнить корзину всеми held-фактами требования ({@see AddToWriteOffDraftCommand}) и вернуть id черновика. */
    private function startWriteOff(string $profileId, string $requirementId): string
    {
        $this->commandBus->execute(new AddToWriteOffDraftCommand($profileId, $requirementId, null));
        $this->reload();
        $act = $this->writeOffActRepo->findOpenDraft($requirementId, $this->organizationIdOf($profileId));
        self::assertNotNull($act, 'черновик создан наполнением корзины с карточки выдачи');

        return $act->getId();
    }

    private function organizationIdOf(string $profileId): string
    {
        /** @var GetProfileQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));
        self::assertNotNull($result->profile);

        return $result->profile->organizationId;
    }

    /**
     * Оформить акт + прогнать async-обработку вручную: в тестах воркер не крутит, поэтому эмулируем его.
     *
     * @param list<string> $profileIds все люди акта — fan-out пересчёта ({@see WriteOffActSigned})
     */
    private function signWriteOff(string $actId, string $requirementId, array $profileIds): void
    {
        $this->commandBus->execute(new SignWriteOffActCommand($actId, [['name' => 'Алиханова Н.И.', 'position' => 'рук. ОТиПБ']], '2026-08-10', $this->stageComplianceScan()));
        $this->reload();
        (static::getContainer()->get(RecomputeOnWriteOffActSignedHandler::class))(new WriteOffActSigned($requirementId, $profileIds));
        $this->reload();
    }

    private function firstRecordId(string $profileId): string
    {
        $this->reload();
        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);

        return $pc->getRecords()[0]->getId();
    }

    /** @return array{positionId: string, requirementId: string} Норма с ДВУМЯ позициями (Перчатки + Каска защитная), общая должность. */
    private function createSharedRequirementWithTwoItems(): array
    {
        $pos = $this->commandBus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $req = $this->commandBus->execute(new SaveRequirementCommand(
            null, 'Личная карточка учёта выдачи СИЗ', 'material', [$pos->id],
            [
                ['label' => 'Перчатки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5'],
                ['label' => 'Каска защитная', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1', 'unit' => 'pair', 'basis' => 'п.9'],
            ],
        ));
        \assert($req instanceof SaveRequirementCommandResult);

        return ['positionId' => $pos->id, 'requirementId' => $req->id];
    }

    /**
     * Новая организация+отдел+профиль на уже существующей должности — первый человек акта.
     *
     * @return array{profileId: string, organizationId: string, departmentId: string}
     */
    private function enrollProfileForPosition(string $positionId): array
    {
        $org = $this->commandBus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $this->commandBus->execute(new CreateDepartmentCommand($org->id, 'Цех '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profileId = $this->enrollSecondProfileInOrganization($org->id, $dept->id, $positionId);

        return ['profileId' => $profileId, 'organizationId' => $org->id, 'departmentId' => $dept->id];
    }

    /** Ещё один профиль в УЖЕ СУЩЕСТВУЮЩЕЙ организации/отделе — второй человек акта (кросс-персон). */
    private function enrollSecondProfileInOrganization(string $organizationId, string $departmentId, string $positionId): string
    {
        $profile = $this->commandBus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Петров', firstName: 'Пётр', middleName: 'Петрович',
            positionId: $positionId, organizationId: $organizationId, departmentId: $departmentId,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: '52', shoes: '42', headgear: '56', respirator: null, gloves: '9', height: '176', gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profile->id));

        return $profile->id;
    }

    private function reload(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
