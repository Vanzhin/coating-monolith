<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\ApproveReport\ApproveReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Application\UseCase\Command\RejectReport\RejectReportCommand;
use App\Reports\Application\UseCase\Command\SaveReportContent\SaveReportContentCommand;
use App\Reports\Application\UseCase\Command\SubmitForReview\SubmitForReviewCommand;
use App\Reports\Domain\Aggregate\Report\ReportStatus;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Статусный workflow отчёта: авто-«в работу» при сохранении, строгая валидация на отправке,
 * утверждение/заморозка, отклонение с причиной и её снятие при доработке.
 */
final class ReportWorkflowTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private ReportRepositoryInterface $reports;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $reportIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->reports = $c->get(ReportRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        try {
            foreach ($this->reportIds as $id) {
                if (null !== ($r = $this->reports->findOneById($id))) {
                    $this->reports->remove($r);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function validContent(): array
    {
        return [
            'control_area' => ['description' => 'Балка Б-1, нижняя полка', 'area' => 2.5],
            'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½'],
            'conclusion' => ['text' => 'Соответствует регламенту.'],
        ];
    }

    private function createReport(): string
    {
        $result = $this->commandBus->execute(new CreateReportCommand(ReportType::TrialApplication));
        \assert($result instanceof CreateReportCommandResult);
        $this->reportIds[] = $result->id;

        return $result->id;
    }

    private function status(string $id): ReportStatus
    {
        $this->em->clear();
        $report = $this->reports->findOneById($id);
        self::assertNotNull($report);

        return $report->getStatus();
    }

    public function test_saving_content_auto_starts_work(): void
    {
        $id = $this->createReport();
        self::assertSame(ReportStatus::Created, $this->status($id));

        $this->commandBus->execute(new SaveReportContentCommand($id, ['notes' => ['text' => 'черновик']]));

        self::assertSame(ReportStatus::InWork, $this->status($id));
    }

    public function test_submit_incomplete_is_blocked_by_strict_validation(): void
    {
        $id = $this->createReport();
        // Лёгкое сохранение неполного черновика проходит (тип-чек заполненного), но submit — нет.
        $this->commandBus->execute(new SaveReportContentCommand($id, ['notes' => ['text' => 'мало данных']]));

        $this->expectException(AppException::class);
        $this->commandBus->execute(new SubmitForReviewCommand($id));
    }

    public function test_happy_path_submit_approve_then_frozen(): void
    {
        $id = $this->createReport();
        $this->commandBus->execute(new SaveReportContentCommand($id, $this->validContent()));
        $this->commandBus->execute(new SubmitForReviewCommand($id));
        self::assertSame(ReportStatus::UnderReview, $this->status($id));

        $this->commandBus->execute(new ApproveReportCommand($id));
        self::assertSame(ReportStatus::Approved, $this->status($id));

        // Утверждён — заморожен: сохранение отбивается доменом.
        $this->expectException(AppException::class);
        $this->commandBus->execute(new SaveReportContentCommand($id, $this->validContent()));
    }

    public function test_reject_with_reason_then_resume_clears_it(): void
    {
        $id = $this->createReport();
        $this->commandBus->execute(new SaveReportContentCommand($id, $this->validContent()));
        $this->commandBus->execute(new SubmitForReviewCommand($id));
        $this->commandBus->execute(new RejectReportCommand($id, 'Нет данных по приборам'));

        $this->em->clear();
        $rejected = $this->reports->findOneById($id);
        self::assertNotNull($rejected);
        self::assertSame(ReportStatus::Rejected, $rejected->getStatus());
        self::assertSame('Нет данных по приборам', $rejected->getRejectionReason());

        // Доработка: снова сохраняем — возврат «в работу», причина снята.
        $this->commandBus->execute(new SaveReportContentCommand($id, $this->validContent()));
        $this->em->clear();
        $resumed = $this->reports->findOneById($id);
        self::assertNotNull($resumed);
        self::assertSame(ReportStatus::InWork, $resumed->getStatus());
        self::assertNull($resumed->getRejectionReason());
    }

    public function test_cannot_approve_before_review(): void
    {
        $id = $this->createReport();
        $this->commandBus->execute(new SaveReportContentCommand($id, $this->validContent())); // InWork

        $this->expectException(AppException::class);
        $this->commandBus->execute(new ApproveReportCommand($id)); // В работе → Утверждён нельзя
    }
}
