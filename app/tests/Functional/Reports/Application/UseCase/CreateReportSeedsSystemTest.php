<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Application\UseCase\Command\SaveReportContent\SaveReportContentCommand;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Засев блока «Система (план)» при создании отчёта из CoatingSystem и его несменяемость при сохранении.
 */
final class CreateReportSeedsSystemTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use CoatingSystemLayerTestFixtureTrait;

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
        $this->setUpFixture($c, $this->em); // одна система: 1 слой (position=1, dft=80), покрытие EP, цвет серый
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
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    public function test_creating_report_with_system_seeds_planned_layers(): void
    {
        $reportId = $this->createReportWithSystem();
        $this->em->clear();

        $content = $this->reports->findOneById($reportId)?->getContent();
        self::assertIsArray($content);
        self::assertArrayHasKey('system', $content);
        self::assertIsArray($content['system']);
        $layers = $content['system']['layers'];
        self::assertIsArray($layers);
        self::assertCount(1, $layers);

        $layer = $layers[0];
        self::assertIsArray($layer);
        self::assertIsArray($layer['material']);
        self::assertSame((string) $this->coatingId, $layer['material']['id']);
        self::assertNotSame('', $layer['material']['title']);
        self::assertSame(80, $layer['dft_nominal']);
        self::assertSame(1, $layer['order']);
        self::assertNotSame('', $layer['color']);
    }

    public function test_saving_content_preserves_seeded_system(): void
    {
        $reportId = $this->createReportWithSystem();

        // Клиент сохраняет черновик без блока system — план обязан сохраниться.
        $this->commandBus->execute(new SaveReportContentCommand($reportId, ['notes' => ['text' => 'черновик']]));
        $this->em->clear();

        $content = $this->reports->findOneById($reportId)?->getContent();
        self::assertIsArray($content);
        self::assertArrayHasKey('system', $content); // засев уцелел
        self::assertIsArray($content['notes']);
        self::assertSame('черновик', $content['notes']['text']);
    }

    private function createReportWithSystem(): string
    {
        $result = $this->commandBus->execute(new CreateReportCommand(
            type: ReportType::TrialApplication,
            systemId: (string) $this->systemId,
        ));
        \assert($result instanceof CreateReportCommandResult);
        $this->reportIds[] = $result->id;

        return $result->id;
    }
}
