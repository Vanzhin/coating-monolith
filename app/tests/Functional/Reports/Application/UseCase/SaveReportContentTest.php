<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Application\UseCase\Command\SaveReportContent\SaveReportContentCommand;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SaveReportContentTest extends KernelTestCase
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

    private function createReport(): string
    {
        $result = $this->commandBus->execute(new CreateReportCommand(ReportType::TrialApplication));
        \assert($result instanceof CreateReportCommandResult);
        $this->reportIds[] = $result->id;

        return $result->id;
    }

    public function test_save_persists_content(): void
    {
        $id = $this->createReport();
        $content = [
            'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½'],
            'notes' => ['text' => 'Без замечаний.'],
        ];

        $this->commandBus->execute(new SaveReportContentCommand($id, $content));
        $this->em->clear();

        // assertEquals, не assertSame: jsonb нормализует порядок ключей (===-идентичность не гарантирована).
        self::assertEquals($content, $this->reports->findOneById($id)?->getContent());
    }

    public function test_invalid_enum_rejected(): void
    {
        $id = $this->createReport();

        $this->expectException(AppException::class);
        $this->commandBus->execute(new SaveReportContentCommand($id, [
            'surface_prep' => ['rustGrade' => 'ZZZ'],
        ]));
    }

    public function test_save_on_missing_report_throws(): void
    {
        $this->expectException(AppException::class);
        $this->commandBus->execute(new SaveReportContentCommand('00000000-0000-0000-0000-000000000000', []));
    }
}
