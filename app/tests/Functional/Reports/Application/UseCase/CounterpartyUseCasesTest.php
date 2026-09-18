<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Reports\Application\UseCase\Command\DeleteCounterparty\DeleteCounterpartyCommand;
use App\Reports\Application\UseCase\Command\UpdateCounterparty\UpdateCounterpartyCommand;
use App\Reports\Application\UseCase\Query\GetPagedCounterparties\GetPagedCounterpartiesQuery;
use App\Reports\Application\UseCase\Query\GetPagedCounterparties\GetPagedCounterpartiesQueryResult;
use App\Reports\Application\UseCase\Query\SuggestCounterparties\SuggestCounterpartiesQuery;
use App\Reports\Application\UseCase\Query\SuggestCounterparties\SuggestCounterpartiesQueryResult;
use App\Reports\Domain\Repository\CounterpartiesFilter;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CounterpartyUseCasesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private CounterpartyRepositoryInterface $repo;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->repo = $container->get(CounterpartyRepositoryInterface::class);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $c = $this->repo->findOneById($id);
                if (null !== $c) {
                    $this->repo->remove($c);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function create(string $title, ?string $description = null): CreateCounterpartyCommandResult
    {
        $result = $this->commandBus->execute(new CreateCounterpartyCommand($title, $description));
        \assert($result instanceof CreateCounterpartyCommandResult);
        $this->createdIds[] = $result->id;

        return $result;
    }

    public function test_create_persists_with_description(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $result = $this->create('ЕвроХим-'.$suffix, 'Заказчик');

        $loaded = $this->repo->findOneById($result->id);
        self::assertNotNull($loaded);
        self::assertSame('ЕвроХим-'.$suffix, $loaded->getTitle());
        self::assertSame('Заказчик', $loaded->getDescription());
    }

    public function test_create_trims_title(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $result = $this->create('  НГТ-'.$suffix.'  ');
        self::assertSame('НГТ-'.$suffix, $result->title);
    }

    public function test_create_duplicate_title_throws(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->create('Дубль-'.$suffix);

        $this->expectException(AppException::class);
        $this->create('Дубль-'.$suffix);
    }

    public function test_update_changes_title_and_description(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('Старое-'.$suffix, 'старое опис.');

        $this->commandBus->execute(new UpdateCounterpartyCommand($created->id, 'Новое-'.$suffix, 'новое опис.'));

        $loaded = $this->repo->findOneById($created->id);
        self::assertNotNull($loaded);
        self::assertSame('Новое-'.$suffix, $loaded->getTitle());
        self::assertSame('новое опис.', $loaded->getDescription());
    }

    public function test_delete_removes(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('Удаляемый-'.$suffix);

        $this->commandBus->execute(new DeleteCounterpartyCommand($created->id));

        self::assertNull($this->repo->findOneById($created->id));
    }

    public function test_suggest_finds_by_prefix(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('Суггест-'.$suffix);

        $result = $this->queryBus->execute(new SuggestCounterpartiesQuery('Суггест-'.$suffix, 10));
        \assert($result instanceof SuggestCounterpartiesQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->counterparties);
        self::assertContains($created->id, $ids);
    }

    public function test_paged_list_filters_by_title(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('Списочный-'.$suffix);

        $result = $this->queryBus->execute(new GetPagedCounterpartiesQuery(
            new CounterpartiesFilter(pager: Pager::fromPage(1, 50), title: 'Списочный-'.$suffix),
        ));
        \assert($result instanceof GetPagedCounterpartiesQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->counterparties);
        self::assertContains($created->id, $ids);
    }
}
