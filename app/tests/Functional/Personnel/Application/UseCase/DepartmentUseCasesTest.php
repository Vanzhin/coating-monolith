<?php

declare(strict_types=1);

namespace App\Tests\Functional\Personnel\Application\UseCase;

use App\Personnel\Application\UseCase\Command\AssignDepartmentHead\AssignDepartmentHeadCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\DeleteDepartment\DeleteDepartmentCommand;
use App\Personnel\Application\UseCase\Command\MoveDepartment\MoveDepartmentCommand;
use App\Personnel\Application\UseCase\Command\UpdateDepartment\UpdateDepartmentCommand;
use App\Personnel\Application\UseCase\Query\GetCompanyDepartmentTree\GetCompanyDepartmentTreeQuery;
use App\Personnel\Application\UseCase\Query\GetCompanyDepartmentTree\GetCompanyDepartmentTreeQueryResult;
use App\Personnel\Application\UseCase\Query\GetDepartmentsByIds\GetDepartmentsByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetDepartmentsByIds\GetDepartmentsByIdsQueryResult;
use App\Personnel\Application\UseCase\Query\SuggestDepartments\SuggestDepartmentsQuery;
use App\Personnel\Application\UseCase\Query\SuggestDepartments\SuggestDepartmentsQueryResult;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
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

final class DepartmentUseCasesTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private DepartmentRepositoryInterface $repo;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->commandBus = $container->get(CommandBusInterface::class);
        $this->queryBus = $container->get(QueryBusInterface::class);
        $this->repo = $container->get(DepartmentRepositoryInterface::class);

        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            foreach (array_reverse($this->createdIds) as $id) {
                $d = $this->repo->findOneById($id);
                if (null !== $d) {
                    $this->repo->remove($d);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    private function create(string $title, string $companyId, ?string $parentId = null, ?string $headUserUlid = null): CreateDepartmentCommandResult
    {
        $result = $this->commandBus->execute(new CreateDepartmentCommand($companyId, $title, $parentId, $headUserUlid));
        \assert($result instanceof CreateDepartmentCommandResult);
        $this->createdIds[] = $result->id;

        return $result;
    }

    public function test_create_root_and_child_persists(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $child = $this->create('Цех '.uniqid('', true), $companyId, $root->id);

        $loadedChild = $this->repo->findOneById($child->id);
        self::assertNotNull($loadedChild);
        self::assertSame($root->id, $loadedChild->getParentId());
        self::assertSame($companyId, $loadedChild->getCompanyId());
    }

    public function test_move_to_valid_parent_updates_parent(): void
    {
        $companyId = UuidService::generate();
        $branchA = $this->create('Филиал А '.uniqid('', true), $companyId);
        $branchB = $this->create('Филиал Б '.uniqid('', true), $companyId);
        $node = $this->create('Отдел '.uniqid('', true), $companyId, $branchA->id);

        $this->commandBus->execute(new MoveDepartmentCommand($node->id, $branchB->id));

        $loaded = $this->repo->findOneById($node->id);
        self::assertNotNull($loaded);
        self::assertSame($branchB->id, $loaded->getParentId());
    }

    public function test_move_into_own_descendant_throws_cycle(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $child = $this->create('Цех '.uniqid('', true), $companyId, $root->id);

        $this->expectException(AppException::class);
        $this->commandBus->execute(new MoveDepartmentCommand($root->id, $child->id));
    }

    public function test_move_to_other_company_throws(): void
    {
        $companyA = UuidService::generate();
        $companyB = UuidService::generate();
        $node = $this->create('Отдел А '.uniqid('', true), $companyA);
        $foreignParent = $this->create('Чужая дирекция '.uniqid('', true), $companyB);

        $this->expectException(AppException::class);
        $this->commandBus->execute(new MoveDepartmentCommand($node->id, $foreignParent->id));
    }

    public function test_move_missing_department_throws_not_found(): void
    {
        $this->expectException(AppException::class);
        $this->commandBus->execute(new MoveDepartmentCommand('00000000-0000-0000-0000-000000000000', null));
    }

    public function test_assign_head_sets_and_clears(): void
    {
        $companyId = UuidService::generate();
        $node = $this->create('Отдел '.uniqid('', true), $companyId);
        $headUlid = UuidService::generateUlid();

        $this->commandBus->execute(new AssignDepartmentHeadCommand($node->id, $headUlid));
        $loaded = $this->repo->findOneById($node->id);
        self::assertNotNull($loaded);
        self::assertSame($headUlid, $loaded->getHeadUserUlid());

        $this->commandBus->execute(new AssignDepartmentHeadCommand($node->id, null));
        $reloaded = $this->repo->findOneById($node->id);
        self::assertNotNull($reloaded);
        self::assertNull($reloaded->getHeadUserUlid());
    }

    public function test_update_renames(): void
    {
        $companyId = UuidService::generate();
        $node = $this->create('Старое '.uniqid('', true), $companyId);
        $newTitle = 'Новое '.uniqid('', true);

        $this->commandBus->execute(new UpdateDepartmentCommand($node->id, $newTitle));

        $loaded = $this->repo->findOneById($node->id);
        self::assertNotNull($loaded);
        self::assertSame($newTitle, $loaded->getTitle());
    }

    public function test_delete_leaf_removes(): void
    {
        $companyId = UuidService::generate();
        $node = $this->create('Удаляемая '.uniqid('', true), $companyId);

        $this->commandBus->execute(new DeleteDepartmentCommand($node->id));

        self::assertNull($this->repo->findOneById($node->id));
    }

    public function test_delete_node_with_children_throws(): void
    {
        $companyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $this->create('Цех '.uniqid('', true), $companyId, $root->id);

        $this->expectException(AppException::class);
        $this->commandBus->execute(new DeleteDepartmentCommand($root->id));
    }

    public function test_regular_user_cannot_create(): void
    {
        // Не персистим — токену для проверки ролей персистентность не нужна, только roles().
        $user = new User(new Email('department_'.bin2hex(random_bytes(4)).'@example.com'));

        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new PreAuthenticatedToken($user, 'test', $user->getRoles()),
        );

        $this->expectException(ForbiddenException::class);
        $this->commandBus->execute(new CreateDepartmentCommand(UuidService::generate(), 'Не пройдёт '.uniqid('', true)));
    }

    public function test_regular_user_cannot_delete(): void
    {
        $companyId = UuidService::generate();
        $node = $this->create('Отдел '.uniqid('', true), $companyId);

        $user = new User(new Email('department_del_'.bin2hex(random_bytes(4)).'@example.com'));
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new PreAuthenticatedToken($user, 'test', $user->getRoles()),
        );

        $this->expectException(ForbiddenException::class);
        $this->commandBus->execute(new DeleteDepartmentCommand($node->id));
    }

    public function test_company_tree_builds_correct_hierarchy(): void
    {
        $companyId = UuidService::generate();
        $otherCompanyId = UuidService::generate();
        $root = $this->create('Дирекция '.uniqid('', true), $companyId);
        $childA = $this->create('Цех А '.uniqid('', true), $companyId, $root->id);
        $childB = $this->create('Цех Б '.uniqid('', true), $companyId, $root->id);
        $grandchild = $this->create('Участок '.uniqid('', true), $companyId, $childA->id);
        $this->create('Чужой корень '.uniqid('', true), $otherCompanyId);

        $result = $this->queryBus->execute(new GetCompanyDepartmentTreeQuery($companyId));
        \assert($result instanceof GetCompanyDepartmentTreeQueryResult);

        self::assertCount(1, $result->roots);
        $rootNode = $result->roots[0];
        self::assertSame($root->id, $rootNode->id);
        self::assertCount(2, $rootNode->children);

        $childIds = array_map(static fn ($n) => $n->id, $rootNode->children);
        self::assertContains($childA->id, $childIds);
        self::assertContains($childB->id, $childIds);

        $childANode = current(array_filter($rootNode->children, static fn ($n) => $n->id === $childA->id));
        self::assertNotFalse($childANode);
        self::assertCount(1, $childANode->children);
        self::assertSame($grandchild->id, $childANode->children[0]->id);
    }

    public function test_suggest_finds_by_prefix(): void
    {
        $companyId = UuidService::generate();
        $suffix = bin2hex(random_bytes(3));
        $created = $this->create('Саджест-'.$suffix, $companyId);

        $result = $this->queryBus->execute(new SuggestDepartmentsQuery('Саджест-'.$suffix, 10));
        \assert($result instanceof SuggestDepartmentsQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->departments);
        self::assertContains($created->id, $ids);
    }

    public function test_by_ids_returns_requested_departments(): void
    {
        $companyId = UuidService::generate();
        $a = $this->create('Отдел А '.uniqid('', true), $companyId);
        $b = $this->create('Отдел Б '.uniqid('', true), $companyId);
        $this->create('Не в выборке '.uniqid('', true), $companyId);

        $result = $this->queryBus->execute(new GetDepartmentsByIdsQuery(new StringCollection($a->id, $b->id)));
        \assert($result instanceof GetDepartmentsByIdsQueryResult);

        $ids = array_map(static fn ($dto) => $dto->id, $result->departments);
        self::assertCount(2, $result->departments);
        self::assertContains($a->id, $ids);
        self::assertContains($b->id, $ids);
    }
}
