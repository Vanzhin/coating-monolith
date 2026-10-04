<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Event\RecomputeOnProfileSavedHandler;
use App\Compliance\Application\Event\RecomputeOnRequirementChangedHandler;
use App\Compliance\Application\UseCase\Command\DeleteDraft\DeleteDraftCommand;
use App\Compliance\Application\UseCase\Command\FormDraft\FormDraftCommand;
use App\Compliance\Application\UseCase\Command\FormDraftsForRequirement\FormDraftsForRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveDraft\SaveDraftCommand;
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

    public function test_form_drafts_button_defers_to_worker_and_is_idempotent(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance();

        // Кнопка только диспатчит событие в воркер — синхронно черновик НЕ формируется (людей может быть много).
        $this->commandBus->execute(new FormDraftsForRequirementCommand($r));
        $this->em()->clear();
        self::assertNull($this->reload($p)->openDraftFor($r), 'работа ушла в воркер — синхронно черновика нет');

        // Прогон воркера (обработчик события) формирует черновик; повтор не плодит.
        $handler = static::getContainer()->get(RecomputeOnRequirementChangedHandler::class);
        ($handler)(new RequirementChanged($r));
        $this->em()->clear();
        self::assertNotNull($this->reload($p)->openDraftFor($r));

        ($handler)(new RequirementChanged($r));
        $this->em()->clear();
        $open = 0;
        foreach ($this->reload($p)->getDocuments() as $d) {
            if ($d->requirementId() === $r && $d->isDraft()) {
                ++$open;
            }
        }
        self::assertSame(1, $open, 'повторная обработка не плодит черновики');
    }

    public function test_worker_rebuilds_card_for_covered_person_without_projection(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance();
        // Убираем проекцию (как после сброса данных): человек покрыт требованием, но карточки нет.
        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        $this->em()->remove($pc); // карточки у покрытого человека больше нет
        $this->em()->flush();
        $this->em()->clear();

        (static::getContainer()->get(RecomputeOnRequirementChangedHandler::class))(new RequirementChanged($r));
        $this->em()->clear();

        $rebuilt = $this->reload($p); // reload сам гарантирует, что карточка снова есть
        self::assertNotNull($rebuilt->openDraftFor($r), 'воркер пересобрал карточку покрытого человека и завёл черновик');
    }

    public function test_no_draft_when_all_ok(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->formAndSign($p, $r, $k);
        $this->em()->clear();

        (static::getContainer()->get(RecomputeOnRequirementChangedHandler::class))(new RequirementChanged($r));
        $this->em()->clear();
        self::assertNull($this->reload($p)->openDraftFor($r), 'всё выдано → черновик не нужен');
    }

    public function test_save_without_scan_keeps_cart_unissued(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $docId = $this->formDraftAndGetId($p, $r);

        $this->commandBus->execute(new SaveDraftCommand(
            $p, $docId, '2026-03-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']],
            'К-1', 'Петров П. П.', // без stagedFileId → сохранение черновика, не оформление
        ));

        $this->em()->clear();
        $pc = $this->reload($p);
        self::assertSame(0.0, $pc->heldOf($k), 'сохранено, но не выдано (скан не приложен)');
        $draft = $pc->openDraftFor($r);
        self::assertNotNull($draft, 'черновик остался черновиком');
        self::assertSame('К-1', $draft->actNumber());
        self::assertSame(10.0, $this->cartSum($pc, $draft->getId(), $k), 'строки в корзине');
    }

    public function test_sign_draft_records_and_signs(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $docId = $this->formDraftAndGetId($p, $r);

        $this->commandBus->execute(new SaveDraftCommand(
            $p, $docId, '2026-03-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']],
            'К-1', 'Петров П. П.',
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
            $this->commandBus->execute(new SaveDraftCommand(
                $p, $docId, '2026-03-01',
                [['obligationKey' => $k, 'amount' => '5', 'unit' => 'pair']], // меньше нормы (10)
                'К-1', 'Петров П. П.',
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

        $this->commandBus->execute(new SaveDraftCommand(
            $p, $docId, '2026-03-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']], // норма перчаток
            'К-1', 'Петров П. П.',
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
        $this->commandBus->execute(new SaveDraftCommand(
            $p, $docId, '2026-03-01',
            [['obligationKey' => $k, 'amount' => '10', 'unit' => 'pair']],
            'К-1', 'Петров П. П.',
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

    public function test_event_populates_cart_with_deficit_and_supplements_idempotently(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $handler = static::getContainer()->get(RecomputeOnRequirementChangedHandler::class);
        ($handler)(new RequirementChanged($r));
        $this->em()->clear();

        $pc = $this->reload($p);
        $draft = $pc->openDraftFor($r);
        self::assertNotNull($draft, 'событие завело черновик-корзину');
        self::assertSame(10.0, $this->cartSum($pc, $draft->getId(), $k), 'корзина наполнена дефицитом нормы (10 пар)');

        // Повтор события не плодит ни черновик, ни строки корзины.
        ($handler)(new RequirementChanged($r));
        $this->em()->clear();
        $pc = $this->reload($p);
        $draft = $pc->openDraftFor($r);
        self::assertNotNull($draft);
        self::assertSame(10.0, $this->cartSum($pc, $draft->getId(), $k), 'повтор идемпотентен');
        $drafts = 0;
        foreach ($pc->getDocuments() as $d) {
            if ($d->requirementId() === $r && $d->isDraft()) {
                ++$drafts;
            }
        }
        self::assertSame(1, $drafts, 'черновик по-прежнему один');
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
        $this->commandBus->execute(new SaveDraftCommand(
            $profileId, $docId, '2026-03-01',
            [['obligationKey' => $key, 'amount' => '10', 'unit' => 'pair']],
            'К-1', 'Петров П. П.',
            $this->stageComplianceScan(),
        ));
    }

    private function cartSum(ProfileCompliance $pc, string $documentId, string $key): float
    {
        $sum = 0.0;
        foreach ($pc->getRecords() as $record) {
            if ($record->documentId() === $documentId && $record->obligationKey() === $key) {
                $sum += $record->quantity()->amount ?? 0.0;
            }
        }

        return $sum;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
