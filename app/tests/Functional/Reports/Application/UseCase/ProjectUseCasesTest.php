<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Reports\Application\UseCase\Command\CreateProject\CreateProjectCommand;
use App\Reports\Application\UseCase\Command\CreateProject\CreateProjectCommandResult;
use App\Reports\Application\UseCase\Command\DeleteProject\DeleteProjectCommand;
use App\Reports\Application\UseCase\Command\UpdateProject\UpdateProjectCommand;
use App\Reports\Application\UseCase\Query\GetProject\GetProjectQuery;
use App\Reports\Application\UseCase\Query\GetProject\GetProjectQueryResult;
use App\Reports\Application\UseCase\Query\SuggestProjects\SuggestProjectsQuery;
use App\Reports\Application\UseCase\Query\SuggestProjects\SuggestProjectsQueryResult;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Reports\Domain\Repository\ProjectRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProjectUseCasesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private ProjectRepositoryInterface $projects;
    private CounterpartyRepositoryInterface $counterparties;
    /** @var list<string> */
    private array $projectIds = [];
    /** @var list<string> */
    private array $counterpartyIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->projects = $container->get(ProjectRepositoryInterface::class);
        $this->counterparties = $container->get(CounterpartyRepositoryInterface::class);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        try {
            foreach ($this->projectIds as $id) {
                $p = $this->projects->findOneById($id);
                if (null !== $p) {
                    $this->projects->remove($p);
                }
            }
            foreach ($this->counterpartyIds as $id) {
                $c = $this->counterparties->findOneById($id);
                if (null !== $c) {
                    $this->counterparties->remove($c);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function counterparty(string $title): string
    {
        $result = $this->commandBus->execute(new CreateCounterpartyCommand($title));
        \assert($result instanceof CreateCounterpartyCommandResult);
        $this->counterpartyIds[] = $result->id;

        return $result->id;
    }

    private function project(string $title, string $counterpartyId, ?string $description = null): CreateProjectCommandResult
    {
        $result = $this->commandBus->execute(new CreateProjectCommand($title, $counterpartyId, $description));
        \assert($result instanceof CreateProjectCommandResult);
        $this->projectIds[] = $result->id;

        return $result;
    }

    public function test_create_persists_with_counterparty(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $cp = $this->counterparty('Заказчик-'.$suffix);
        $created = $this->project('УКК-'.$suffix, $cp, 'Обогатительная фабрика');

        $loaded = $this->projects->findOneById($created->id);
        self::assertNotNull($loaded);
        self::assertSame('УКК-'.$suffix, $loaded->getTitle());
        self::assertSame($cp, $loaded->getCounterparty()->getId());
        self::assertSame('Обогатительная фабрика', $loaded->getDescription());
    }

    public function test_create_with_unknown_counterparty_throws(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->expectException(AppException::class);
        $this->project('Сирота-'.$suffix, 'no-such-counterparty-id');
    }

    public function test_create_duplicate_title_throws(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $cp = $this->counterparty('Зак-'.$suffix);
        $this->project('ДублеПроект-'.$suffix, $cp);

        $this->expectException(AppException::class);
        $this->project('ДублеПроект-'.$suffix, $cp);
    }

    public function test_update_changes_title_and_counterparty(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $cpA = $this->counterparty('ЗакА-'.$suffix);
        $cpB = $this->counterparty('ЗакБ-'.$suffix);
        $created = $this->project('Старый-'.$suffix, $cpA);

        $this->commandBus->execute(new UpdateProjectCommand($created->id, 'Новый-'.$suffix, $cpB, 'опис.'));

        $result = $this->queryBus->execute(new GetProjectQuery($created->id));
        \assert($result instanceof GetProjectQueryResult);
        self::assertNotNull($result->project);
        self::assertSame('Новый-'.$suffix, $result->project->title);
        self::assertSame($cpB, $result->project->counterpartyId);
        self::assertSame('опис.', $result->project->description);
    }

    public function test_delete_removes(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $cp = $this->counterparty('ЗакУд-'.$suffix);
        $created = $this->project('Удаляемый-'.$suffix, $cp);

        $this->commandBus->execute(new DeleteProjectCommand($created->id));

        self::assertNull($this->projects->findOneById($created->id));
    }

    public function test_suggest_finds_by_prefix(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $cp = $this->counterparty('ЗакСаг-'.$suffix);
        $created = $this->project('Суггест-'.$suffix, $cp);

        $result = $this->queryBus->execute(new SuggestProjectsQuery('Суггест-'.$suffix, 10));
        \assert($result instanceof SuggestProjectsQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->projects);
        self::assertContains($created->id, $ids);
    }
}
