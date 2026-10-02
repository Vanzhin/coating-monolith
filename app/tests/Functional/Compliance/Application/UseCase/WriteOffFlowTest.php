<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\WriteOffActProjector;
use App\Compliance\Application\UseCase\Command\SetWriteOffReasons\SetWriteOffReasonsCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\WriteOffPositions\WriteOffPositionsCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
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
 * Флоу списания (корзина): «Списать» кладёт позицию в черновик акта списания БЕЗ эффекта (карточка не
 * пересчитывается); причины ставятся отдельно; оформление акта (комиссия+№+скан) замораживает И гасит факты →
 * позиция освобождается → заводится черновик новой выдачи. docx-акт рендерит позиции и причины.
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

    public function test_write_off_puts_position_in_draft_basket_without_effect(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // действующая карточка

        $recordId = $this->firstRecordId($p);
        $this->commandBus->execute(new WriteOffPositionsCommand($p, $r, [['recordId' => $recordId, 'quantity' => 1.0]]));

        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertCount(1, $pc->getWriteOffActs());
        self::assertTrue($pc->getWriteOffActs()[0]->isDraft());
        self::assertNull($pc->openDraftFor($r), 'эффекта нет — новый черновик выдачи не заводится до оформления акта');
    }

    public function test_partial_write_off_then_deficit_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance(); // норма 10 (материальная)
        $this->issueCard($p, $r, $k); // выдано 10 ≥ нормы
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        $recordId = $pc->getRecords()[0]->getId();

        $this->commandBus->execute(new WriteOffPositionsCommand($p, $r, [['recordId' => $recordId, 'quantity' => 1.0]]));
        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        $actId = $pc->getWriteOffActs()[0]->getId();
        $portionId = $pc->itemsOfWriteOffAct($actId)[0]->getId();
        $this->commandBus->execute(new SetWriteOffReasonsCommand($p, $actId, [$portionId => 'physical_wear']));
        $this->commandBus->execute(new SignWriteOffActCommand($p, $actId, $p, [$p], '39', '2026-08-10', $this->stageComplianceScan()));

        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertTrue($pc->getWriteOffActs()[0]->isSigned());
        self::assertNotNull($pc->openDraftFor($r), 'дефицит (на руках 9 < норма 10) → черновик новой выдачи');
    }

    public function test_sign_write_off_act_frees_position_and_creates_new_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $held = $this->repo->findByProfile($p)?->heldOf($k) ?? 0.0;
        $this->commandBus->execute(new WriteOffPositionsCommand($p, $r, [['recordId' => $recordId, 'quantity' => $held]]));

        [$actId, $portionId] = $this->basketItem($p);
        $this->commandBus->execute(new SetWriteOffReasonsCommand($p, $actId, [$portionId => 'physical_wear']));
        $this->commandBus->execute(new SignWriteOffActCommand($p, $actId, $p, [$p], '39', '2026-08-10', $this->stageComplianceScan()));

        $this->reload();
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        $act = $pc->getWriteOffActs()[0];
        self::assertTrue($act->isSigned());
        self::assertSame('39', $act->actNumber());
        self::assertNotNull($act->commission());
        self::assertNotNull($pc->openDraftFor($r), 'позиция освободилась полностью → заведён черновик новой выдачи');
    }

    public function test_write_off_act_docx_renders_positions_and_reason(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);
        $recordId = $this->firstRecordId($p);
        $this->commandBus->execute(new WriteOffPositionsCommand($p, $r, [['recordId' => $recordId, 'quantity' => 1.0]]));
        [$actId, $portionId] = $this->basketItem($p);
        $this->commandBus->execute(new SetWriteOffReasonsCommand($p, $actId, [$portionId => 'physical_wear']));

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

    /** @return array{0: string, 1: string} actId, portionId первой порции корзины */
    private function basketItem(string $profileId): array
    {
        $this->reload();
        $pc = $this->repo->findByProfile($profileId);
        self::assertNotNull($pc);
        $actId = $pc->getWriteOffActs()[0]->getId();
        $portionId = $pc->itemsOfWriteOffAct($actId)[0]->getId();

        return [$actId, $portionId];
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
