<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Event\RecomputeOnProfileSavedHandler;
use App\Compliance\Application\Event\RecomputeOnRequirementChangedHandler;
use App\Compliance\Application\UseCase\Command\DeleteDraft\DeleteDraftCommand;
use App\Compliance\Application\UseCase\Command\FormDraft\FormDraftCommand;
use App\Compliance\Application\UseCase\Command\FormDraftsForRequirement\FormDraftsForRequirementCommand;
use App\Compliance\Application\UseCase\Command\SignDraft\SignDraftCommand;
use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Event\RequirementChanged;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Personnel\Domain\Event\ProfileSaved;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Флоу черновиков: формирование (одиночное/пакет, с гардами «пусто/уже есть открытый»), оформление черновика
 * строками из формы (факты + подпись), удаление, и событийные хуки (нормы и профиля → черновики).
 */
final class DraftFlowTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private ProfileComplianceRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_form_draft_single_creates_marker_once(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance();

        $this->commandBus->execute(new FormDraftCommand($p, $r));
        $this->commandBus->execute(new FormDraftCommand($p, $r)); // второй — не плодит

        $this->em()->clear();
        $pc = $this->reload($p);
        self::assertCount(1, $pc->getDocuments());
        self::assertTrue($pc->openDraftFor($r)?->isDraft());
    }

    public function test_batch_forms_for_all_and_is_idempotent(): void
    {
        ['requirementId' => $r] = $this->enrollCompliance();

        self::assertSame(1, $this->commandBus->execute(new FormDraftsForRequirementCommand($r)));
        self::assertSame(0, $this->commandBus->execute(new FormDraftsForRequirementCommand($r))); // открытый уже есть
    }

    public function test_no_draft_when_all_ok(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->formAndSign($p, $r, $k);
        $this->em()->clear();

        self::assertSame(0, $this->commandBus->execute(new FormDraftsForRequirementCommand($r)), 'всё ок → черновик не нужен');
    }

    public function test_sign_draft_records_and_signs(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $docId = $this->formDraftAndGetId($p, $r);

        $this->commandBus->execute(new SignDraftCommand(
            $p, $docId, '2026-03-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']],
            $this->stageComplianceScan(),
        ));

        $this->em()->clear();
        $pc = $this->reload($p);
        self::assertCount(1, $pc->signedDocumentsFor($r));
        self::assertCount(1, $pc->getRecords());
        self::assertTrue($pc->getObligations()[0]->isActive());
        self::assertSame('2027-03-01', $pc->getObligations()[0]->nextDueAt()?->format('Y-m-d'));
    }

    public function test_sign_draft_below_norm_throws_and_keeps_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $docId = $this->formDraftAndGetId($p, $r);

        $threw = false;
        try {
            $this->commandBus->execute(new SignDraftCommand(
                $p, $docId, '2026-03-01',
                [['obligationKey' => $k, 'amount' => '5', 'unit' => 'pair']], // меньше нормы (10)
                $this->stageComplianceScan(),
            ));
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertTrue($threw, 'выдача меньше нормы должна падать');
        $this->em()->clear();
        self::assertTrue($this->reload($p)->openDraftFor($r)?->isDraft(), 'черновик не оформлен');
    }

    public function test_personal_item_added_in_act_is_tracked_with_due_date(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $docId = $this->formDraftAndGetId($p, $r);

        $this->commandBus->execute(new SignDraftCommand(
            $p, $docId, '2026-03-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']], // норма перчаток
            $this->stageComplianceScan(),
            [['label' => 'Очки', 'amount' => '1', 'unit' => 'pcs', 'manualDueDate' => '2027-02-01']],
        ));

        $this->em()->clear();
        $pc = $this->reload($p);
        $key = TrackedObligation::keyOf($r, 'Очки');
        $personal = null;
        foreach ($pc->getObligations() as $o) {
            if ($o->key() === $key) {
                $personal = $o;
                break;
            }
        }
        self::assertNotNull($personal, 'персональная позиция материализована из строки акта');
        self::assertSame(TrackedObligation::ORIGIN_PERSONAL, $personal->origin());
        self::assertSame('2027-02-01', $personal->nextDueAt()?->format('Y-m-d'), 'срок = manualDueDate');
        self::assertSame(1.0, $pc->heldOf($key));
    }

    public function test_personal_item_without_due_date_is_rejected(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $docId = $this->formDraftAndGetId($p, $r);

        $this->expectException(AppException::class); // срок окончания обязателен
        $this->commandBus->execute(new SignDraftCommand(
            $p, $docId, '2026-03-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']],
            $this->stageComplianceScan(),
            [['label' => 'Очки', 'amount' => '1', 'unit' => 'pcs', 'manualDueDate' => '']],
        ));
    }

    public function test_delete_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance();
        $docId = $this->formDraftAndGetId($p, $r);

        $this->commandBus->execute(new DeleteDraftCommand($p, $docId));

        $this->em()->clear();
        self::assertCount(0, $this->reload($p)->getDocuments());
    }

    public function test_event_handler_rebuilds_and_forms_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance();
        self::assertCount(0, $this->reload($p)->getDocuments()); // до события карточек нет

        (static::getContainer()->get(RecomputeOnRequirementChangedHandler::class))(new RequirementChanged($r));

        $this->em()->clear();
        $draft = $this->reload($p)->openDraftFor($r);
        self::assertNotNull($draft, 'событие нормы завело черновик');
        self::assertTrue($draft->isDraft());
    }

    public function test_profile_saved_handler_forms_draft(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance();
        self::assertCount(0, $this->reload($p)->getDocuments());

        (static::getContainer()->get(RecomputeOnProfileSavedHandler::class))(new ProfileSaved($p));

        $this->em()->clear();
        $draft = $this->reload($p)->openDraftFor($r);
        self::assertNotNull($draft, 'сохранение профиля завело черновик');
        self::assertTrue($draft->isDraft());
    }

    private function formDraftAndGetId(string $profileId, string $requirementId): string
    {
        $this->commandBus->execute(new FormDraftCommand($profileId, $requirementId));
        $this->em()->clear();
        $draft = $this->reload($profileId)->openDraftFor($requirementId);
        self::assertNotNull($draft);

        return $draft->getId();
    }

    private function reload(string $profileId): ProfileCompliance
    {
        $profileCompliance = $this->repo->findByProfile($profileId);
        self::assertNotNull($profileCompliance);

        return $profileCompliance;
    }

    private function formAndSign(string $profileId, string $requirementId, string $key): void
    {
        $docId = $this->formDraftAndGetId($profileId, $requirementId);
        $this->commandBus->execute(new SignDraftCommand(
            $profileId, $docId, '2026-03-01',
            [['obligationKey' => $key, 'amount' => '10', 'unit' => 'pair']],
            $this->stageComplianceScan(),
        ));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
