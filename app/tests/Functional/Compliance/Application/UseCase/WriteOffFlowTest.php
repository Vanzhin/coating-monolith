<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Event\RecomputeOnWriteOffActSignedHandler;
use App\Compliance\Application\Service\WriteOffActProjector;
use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\StartWriteOffAct\StartWriteOffActCommand;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Event\WriteOffActSigned;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Templating\TemplateFile;
use App\Shared\Infrastructure\Service\TemplateRendering;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Флоу списания: с действующей карточки «Перейти к акту списания» (StartWriteOffAct) создаёт/открывает черновик
 * акта; на его странице указывают количество+причину (SaveWriteOffAct) БЕЗ эффекта; оформление (комиссия+№+скан)
 * замораживает И гасит факты на количество → позиция освобождается/становится дефицитной → черновик новой выдачи.
 */
final class WriteOffFlowTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ProfileComplianceRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_save_portion_without_effect(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // действующая карточка

        $recordId = $this->firstRecordId($p);
        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($p, $actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));

        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertCount(1, $pc->getWriteOffActs());
        self::assertTrue($pc->getWriteOffActs()[0]->isDraft());
        self::assertCount(1, $pc->itemsOfWriteOffAct($actId));
        self::assertNull($pc->openDraftFor($r), 'эффекта нет — новый черновик выдачи не заводится до оформления акта');
    }

    public function test_partial_write_off_then_deficit_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10 (материальная)
        $this->issueCard($p, $r, $k); // выдано 10 ≥ нормы
        $recordId = $this->firstRecordId($p);

        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($p, $actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));
        $this->signWriteOff($p, $r, $actId);

        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertTrue($pc->getWriteOffActs()[0]->isSigned());
        self::assertNotNull($pc->openDraftFor($r), 'дефицит (на руках 9 < норма 10) → черновик новой выдачи');
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
        $this->commandBus->execute(new SaveWriteOffActCommand($p, $actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));
        $this->signWriteOff($p, $r, $actId);

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

    public function test_sign_write_off_act_frees_position_and_creates_new_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $held = $this->repo->findByProfile($p)?->heldOf($k) ?? 0.0;

        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($p, $actId, [['recordId' => $recordId, 'quantity' => $held, 'reason' => 'physical_wear']]));
        $this->signWriteOff($p, $r, $actId);

        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        $act = $pc->getWriteOffActs()[0];
        self::assertTrue($act->isSigned());
        self::assertSame('39', $act->actNumber());
        self::assertNotNull($act->commission());
        self::assertNotNull($pc->openDraftFor($r), 'позиция освободилась полностью → заведён черновик новой выдачи');
    }

    public function test_sign_recompute_and_draft_are_idempotent(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $actId = $this->startWriteOff($p, $r);
        $this->commandBus->execute(new SaveWriteOffActCommand($p, $actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']]));
        $this->signWriteOff($p, $r, $actId); // подпись + первая обработка события

        // Повторная обработка события (воркер мог переобработать) не должна задваивать эффект.
        (static::getContainer()->get(RecomputeOnWriteOffActSignedHandler::class))(new WriteOffActSigned($p, $r));
        $this->reload();

        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(9.0, $pc->heldOf($k), 1e-9, 'количество погашено ровно один раз в синхронной подписи — повтор события его не трогает');
        $openDrafts = 0;
        foreach ($pc->getDocuments() as $doc) {
            if ($doc->requirementId() === $r && $doc->isDraft()) {
                ++$openDrafts;
            }
        }
        self::assertSame(1, $openDrafts, 'повторная обработка события не плодит черновики выдачи');
    }

    public function test_write_off_act_docx_renders_positions_and_reason(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $actId = $this->startWriteOff($p, $r);
        // Комиссия обязательна для рендера (в шаблоне строгий блок {{commission}}…{{/commission}}).
        $this->commandBus->execute(new SaveWriteOffActCommand(
            $p, $actId,
            [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']],
            'А-1', '2026-03-01',
            [['fio' => 'Петров П. П.', 'position' => 'Инженер', 'organization' => 'ООО Тест', 'date' => '2026-03-01']],
        ));

        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        $act = $pc->getWriteOffActs()[0];

        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $this->queryBus->execute(new GetProfileQuery($p));
        self::assertNotNull($profileResult->profile);

        $data = static::getContainer()->get(WriteOffActProjector::class)->project($act, $profileResult->profile);
        $path = static::getContainer()->getParameter('kernel.project_dir').'/src/Compliance/Infrastructure/Resources/templates/writeoff_act.docx';
        $doc = static::getContainer()->get(TemplateRendering::class)->render(new TemplateFile((string) $path), $data);

        self::assertSame('docx', $doc->extension());
        $xml = $this->docxText($doc->content);
        self::assertStringContainsString('Перчатки', $xml);
        self::assertStringContainsString('Физический износ', $xml);
        self::assertStringContainsString('Иванов', $xml);
    }

    private function startWriteOff(string $profileId, string $requirementId): string
    {
        $this->commandBus->execute(new StartWriteOffActCommand($profileId, $requirementId));
        $this->reload();
        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);
        $act = $pc->openWriteOffDraftFor($requirementId);
        self::assertNotNull($act);

        return $act->getId();
    }

    /** Оформить акт + прогнать async-обработку вручную: в тестах воркер не крутит, поэтому эмулируем его. */
    private function signWriteOff(string $profileId, string $requirementId, string $actId): void
    {
        $this->commandBus->execute(new SignWriteOffActCommand($profileId, $actId, [['name' => 'Алиханова Н.И.', 'position' => 'рук. ОТиПБ']], '39', '2026-08-10', $this->stageComplianceScan()));
        $this->reload();
        (static::getContainer()->get(RecomputeOnWriteOffActSignedHandler::class))(new WriteOffActSigned($profileId, $requirementId));
        $this->reload();
    }

    private function firstRecordId(string $profileId): string
    {
        $this->reload();
        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);

        return $pc->getRecords()[0]->getId();
    }

    private function reload(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function docxText(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wo_').'.docx';
        file_put_contents($tmp, $bytes);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }
}
