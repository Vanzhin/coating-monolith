<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Application\UseCase;

use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\DeletePosition\DeletePositionCommand;
use App\Personnel\Application\UseCase\Command\UpdatePosition\UpdatePositionCommand;
use App\Personnel\Application\UseCase\Query\GetPagedPositions\GetPagedPositionsQuery;
use App\Personnel\Application\UseCase\Query\GetPagedPositions\GetPagedPositionsQueryResult;
use App\Personnel\Application\UseCase\Query\GetPositionsByIds\GetPositionsByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetPositionsByIds\GetPositionsByIdsQueryResult;
use App\Personnel\Application\UseCase\Query\SuggestPositions\SuggestPositionsQuery;
use App\Personnel\Application\UseCase\Query\SuggestPositions\SuggestPositionsQueryResult;
use App\Personnel\Domain\Aggregate\Profile\FullName;
use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Aggregate\Profile\Sizes;
use App\Personnel\Domain\Aggregate\Profile\Specification\ProfileSpecification;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Personnel\Domain\Repository\PositionsFilter;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Personnel\Domain\ValueObject\Reference;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class PositionUseCasesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private PositionRepositoryInterface $repo;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->repo = $container->get(PositionRepositoryInterface::class);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach ($this->createdIds as $id) {
                $p = $this->repo->findOneById($id);
                if (null !== $p) {
                    $this->repo->remove($p);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function create(string $title): CreatePositionCommandResult
    {
        $result = $this->commandBus->execute(new CreatePositionCommand($title));
        \assert($result instanceof CreatePositionCommandResult);
        $this->createdIds[] = $result->id;

        return $result;
    }

    public function test_create_persists_and_returns_id_and_title(): void
    {
        $title = 'Маляр '.uniqid('', true);
        $result = $this->create($title);

        self::assertSame($title, $result->title);

        $loaded = $this->repo->findOneById($result->id);
        self::assertNotNull($loaded);
        self::assertSame($title, $loaded->getTitle());
    }

    public function test_create_duplicate_title_throws(): void
    {
        $title = 'Дубль '.uniqid('', true);
        $this->create($title);

        $this->expectException(AppException::class);
        $this->create($title);
    }

    public function test_update_changes_title(): void
    {
        $created = $this->create('Старое '.uniqid('', true));
        $newTitle = 'Новое '.uniqid('', true);

        $this->commandBus->execute(new UpdatePositionCommand($created->id, $newTitle));

        $loaded = $this->repo->findOneById($created->id);
        self::assertNotNull($loaded);
        self::assertSame($newTitle, $loaded->getTitle());
    }

    public function test_update_missing_throws_not_found(): void
    {
        $this->expectException(AppException::class);
        $this->commandBus->execute(new UpdatePositionCommand('00000000-0000-0000-0000-000000000000', 'Что угодно'));
    }

    public function test_delete_removes(): void
    {
        $created = $this->create('Удаляемая '.uniqid('', true));

        $this->commandBus->execute(new DeletePositionCommand($created->id));

        self::assertNull($this->repo->findOneById($created->id));
    }

    public function test_delete_throws_when_position_used_by_profile(): void
    {
        $created = $this->create('Занятая '.uniqid('', true));

        $profileRepo = static::getContainer()->get(ProfileRepositoryInterface::class);
        $profileSpec = static::getContainer()->get(ProfileSpecification::class);
        $profile = new Profile(
            UuidService::generate(),
            UuidService::generateUlid(),
            FullName::of('Иванов', 'Иван'),
            new Reference($created->id, $created->title),
            new Reference(UuidService::generate(), 'Организация'),
            new Reference(UuidService::generate(), 'Отдел'),
            Sizes::empty(),
            null,
            null,
            $profileSpec,
            new \DateTimeImmutable(),
        );
        $profileRepo->add($profile);

        try {
            $this->expectException(AppException::class);
            $this->commandBus->execute(new DeletePositionCommand($created->id));
        } finally {
            $profileRepo->remove($profile);
        }
    }

    public function test_regular_user_cannot_create(): void
    {
        // Не персистим — токену для проверки ролей персистентность не нужна, только roles().
        $user = new User(new Email('position_'.bin2hex(random_bytes(4)).'@example.com'));

        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new PreAuthenticatedToken($user, 'test', $user->getRoles()),
        );

        $this->expectException(ForbiddenException::class);
        $this->commandBus->execute(new CreatePositionCommand('Не пройдёт '.uniqid('', true)));
    }

    public function test_suggest_finds_by_prefix(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('Суггест-'.$suffix);

        $result = $this->queryBus->execute(new SuggestPositionsQuery('Суггест-'.$suffix, 10));
        \assert($result instanceof SuggestPositionsQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->positions);
        self::assertContains($created->id, $ids);
    }

    public function test_by_ids_returns_requested_positions(): void
    {
        $a = $this->create('Прораб '.uniqid('', true));
        $b = $this->create('Бригадир '.uniqid('', true));
        $this->create('Не в выборке '.uniqid('', true));

        $result = $this->queryBus->execute(new GetPositionsByIdsQuery(new StringCollection($a->id, $b->id)));
        \assert($result instanceof GetPositionsByIdsQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->positions);
        self::assertCount(2, $result->positions);
        self::assertContains($a->id, $ids);
        self::assertContains($b->id, $ids);
    }

    public function test_paged_list_filters_by_title(): void
    {
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('Списочная-'.$suffix);

        $result = $this->queryBus->execute(new GetPagedPositionsQuery(
            new PositionsFilter(pager: Pager::fromPage(1, 50), title: 'Списочная-'.$suffix),
        ));
        \assert($result instanceof GetPagedPositionsQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->positions);
        self::assertContains($created->id, $ids);
    }
}
