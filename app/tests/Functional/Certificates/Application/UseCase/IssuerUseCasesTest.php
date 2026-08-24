<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificates\Application\UseCase;

use App\Certificates\Application\UseCase\Command\CreateIssuer\CreateIssuerCommand;
use App\Certificates\Application\UseCase\Command\CreateIssuer\CreateIssuerCommandResult;
use App\Certificates\Application\UseCase\Command\DeleteIssuer\DeleteIssuerCommand;
use App\Certificates\Application\UseCase\Command\UpdateIssuer\UpdateIssuerCommand;
use App\Certificates\Application\UseCase\Query\GetPagedIssuers\GetPagedIssuersQuery;
use App\Certificates\Application\UseCase\Query\GetPagedIssuers\GetPagedIssuersQueryResult;
use App\Certificates\Application\UseCase\Query\SuggestIssuers\SuggestIssuersQuery;
use App\Certificates\Application\UseCase\Query\SuggestIssuers\SuggestIssuersQueryResult;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Certificates\Domain\Repository\IssuersFilter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class IssuerUseCasesTest extends KernelTestCase
{
    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private IssuerRepositoryInterface $repo;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->repo = $container->get(IssuerRepositoryInterface::class);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $issuer = $em->find(Issuer::class, Uuid::fromString($id));
                if (null !== $issuer) {
                    $em->remove($issuer);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function create(string $title): CreateIssuerCommandResult
    {
        $result = $this->commandBus->execute(new CreateIssuerCommand($title));
        \assert($result instanceof CreateIssuerCommandResult);
        $this->createdIds[] = $result->id;

        return $result;
    }

    public function test_create_persists_issuer(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $result = $this->create('НПЦ Самара-'.$suffix);

        self::assertNotSame('', $result->id);
        self::assertSame('НПЦ Самара-'.$suffix, $result->title);

        $loaded = $this->repo->findOneById($result->id);
        self::assertNotNull($loaded);
        self::assertSame('НПЦ Самара-'.$suffix, $loaded->getTitle());
    }

    public function test_create_trims_title(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $result = $this->create('  ЦНИИТС-'.$suffix.'  ');
        self::assertSame('ЦНИИТС-'.$suffix, $result->title);
    }

    public function test_create_duplicate_title_throws(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->create('ЛКП-'.$suffix);

        $this->expectException(AppException::class);
        $this->create('ЛКП-'.$suffix);
    }

    public function test_update_renames_issuer(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('Старое имя-'.$suffix);

        $this->commandBus->execute(new UpdateIssuerCommand($created->id, 'Новое имя-'.$suffix));

        $loaded = $this->repo->findOneById($created->id);
        self::assertNotNull($loaded);
        self::assertSame('Новое имя-'.$suffix, $loaded->getTitle());
    }

    public function test_update_to_taken_title_throws(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $a = $this->create('Занятое-'.$suffix);
        $b = $this->create('Свободное-'.$suffix);

        $this->expectException(AppException::class);
        $this->commandBus->execute(new UpdateIssuerCommand($b->id, 'Занятое-'.$suffix));
        // silence unused
        unset($a);
    }

    public function test_update_missing_issuer_throws(): void
    {
        $this->expectException(AppException::class);
        $this->commandBus->execute(new UpdateIssuerCommand((string) Uuid::v7(), 'Кто-то'));
    }

    public function test_delete_removes_issuer(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('На удаление-'.$suffix);

        $this->commandBus->execute(new DeleteIssuerCommand($created->id));

        self::assertNull($this->repo->findOneById($created->id));
    }

    public function test_get_paged_filters_by_title(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->create('Пейджинг Альфа-'.$suffix);
        $this->create('Пейджинг Бета-'.$suffix);

        $result = $this->queryBus->execute(new GetPagedIssuersQuery(
            new IssuersFilter(pager: Pager::fromPage(1, 10), title: 'Пейджинг Альфа-'.$suffix),
        ));
        \assert($result instanceof GetPagedIssuersQueryResult);

        self::assertCount(1, $result->issuers);
        self::assertSame('Пейджинг Альфа-'.$suffix, $result->issuers[0]->title);
    }

    public function test_suggest_returns_prefix_matches(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $this->create('Суггест-'.$suffix);

        $result = $this->queryBus->execute(new SuggestIssuersQuery('суггест-'.$suffix, 10));
        \assert($result instanceof SuggestIssuersQueryResult);

        self::assertCount(1, $result->issuers);
        self::assertSame('Суггест-'.$suffix, $result->issuers[0]->title);
    }
}
