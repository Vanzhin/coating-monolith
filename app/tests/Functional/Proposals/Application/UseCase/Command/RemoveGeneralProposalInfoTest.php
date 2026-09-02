<?php

declare(strict_types=1);

namespace App\Tests\Functional\Proposals\Application\UseCase\Command;

use App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfo\RemoveGeneralProposalInfoCommand;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemApplicationMethod;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemCorrosiveCategory;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemDurability;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemSurfaceTreatment;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoUnit;
use App\Proposals\Domain\Factory\GeneralProposalInfoFactory;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\AuthenticatesUserTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Регресс на IDOR: раньше проверка владельца была тавтологией (owner === owner, всегда true),
 * и любой авторизованный удалял чужую форму КП. Теперь актор берётся из AuthUserFetcher.
 */
final class RemoveGeneralProposalInfoTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use AuthenticatesUserTrait;

    private const OWNER_ULID = '01OWNER0000000000000000000';
    private const ATTACKER_ULID = '01ATTACKER000000000000000';

    private CommandBusInterface $commandBus;
    private GeneralProposalInfoRepositoryInterface $repo;
    private GeneralProposalInfoFactory $factory;
    private EntityManagerInterface $em;

    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->repo = $c->get(GeneralProposalInfoRepositoryInterface::class);
        $this->factory = $c->get(GeneralProposalInfoFactory::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->createdIds as $id) {
            $entity = $this->repo->findOneById($id);
            if (null !== $entity) {
                $this->repo->remove($entity);
            }
        }
        $this->createdIds = [];
        parent::tearDown();
    }

    public function test_non_owner_cannot_delete_others_proposal(): void
    {
        $id = $this->createProposalOwnedBy(self::OWNER_ULID);
        $this->authenticateAsUser(self::ATTACKER_ULID);

        try {
            $this->commandBus->execute(new RemoveGeneralProposalInfoCommand($id));
            $this->fail('Ожидался ForbiddenException: не-владелец не должен удалять чужую форму.');
        } catch (ForbiddenException) {
            // ожидаемо
        }

        self::assertNotNull($this->repo->findOneById($id), 'Форма не должна быть удалена не-владельцем.');
    }

    public function test_owner_can_delete_own_proposal(): void
    {
        $id = $this->createProposalOwnedBy(self::OWNER_ULID);
        $this->authenticateAsUser(self::OWNER_ULID);

        $this->commandBus->execute(new RemoveGeneralProposalInfoCommand($id));

        self::assertNull($this->repo->findOneById($id), 'Владелец должен мочь удалить свою форму.');
    }

    public function test_manager_can_delete_any_proposal(): void
    {
        $id = $this->createProposalOwnedBy(self::OWNER_ULID);
        $this->authenticateAsSystem();

        $this->commandBus->execute(new RemoveGeneralProposalInfoCommand($id));

        self::assertNull($this->repo->findOneById($id), 'Управляющий (админ/система) должен мочь удалить любую форму.');
    }

    private function createProposalOwnedBy(string $ownerId): string
    {
        $proposal = $this->factory->create(
            'TEST-'.uniqid(),
            $ownerId,
            GeneralProposalInfoUnit::KG->value,
            'Тестовый проект',
            100.0,
            'описание',
            'основание',
            'описание конструкции',
            CoatingSystemDurability::LOW->value,
            CoatingSystemCorrosiveCategory::C1->value,
            CoatingSystemSurfaceTreatment::SA1->value,
            CoatingSystemApplicationMethod::AIR->value,
            30,
        );
        $this->repo->add($proposal);
        $this->createdIds[] = $proposal->getId();

        return $proposal->getId();
    }
}
