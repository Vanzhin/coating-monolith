<?php

declare(strict_types=1);

namespace App\Tests\Functional\Proposals\Application;

use App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfo\RemoveGeneralProposalInfoCommand;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemApplicationMethod;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemCorrosiveCategory;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemDurability;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemSurfaceTreatment;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Proposals\Domain\Service\GeneralProposalInfoMaker;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Гейт владения формой КП: не-владелец не может удалить чужую заявку (закрытие IDOR owner===owner),
 * владелец — может. Аутентифицируемся конкретным не-админ юзером, актор берётся из токена, а не из
 * тела/ресурса.
 */
final class ProposalOwnershipGateTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandBusInterface $commandBus;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->commandBus = static::getContainer()->get(CommandBusInterface::class);
    }

    public function test_non_owner_cannot_delete_foreign_proposal(): void
    {
        $ownerId = $this->persistUser()->getUlid();
        $attacker = $this->persistUser();
        $proposalId = $this->makeProposal($ownerId)->getId();

        $this->authenticateAs($attacker);

        $this->expectException(ForbiddenException::class);
        $this->commandBus->execute(new RemoveGeneralProposalInfoCommand($proposalId));
    }

    public function test_owner_can_delete_own_proposal(): void
    {
        $owner = $this->persistUser();
        $proposalId = $this->makeProposal($owner->getUlid())->getId();

        $this->authenticateAs($owner);
        $this->commandBus->execute(new RemoveGeneralProposalInfoCommand($proposalId));

        $repo = static::getContainer()->get(GeneralProposalInfoRepositoryInterface::class);
        self::assertNull($repo->findOneById($proposalId), 'владелец удалил свою форму');
    }

    private function persistUser(): User
    {
        $user = new User(new Email('prop_'.bin2hex(random_bytes(4)).'@example.com'));
        $user->setPassword('pass', static::getContainer()->get(UserPasswordHasherInterface::class));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function makeProposal(string $ownerId): GeneralProposalInfo
    {
        $proposal = static::getContainer()->get(GeneralProposalInfoMaker::class)->make(
            'КП-'.bin2hex(random_bytes(3)),
            $ownerId,
            'кг',
            'Проект',
            10.0,
            null,
            null,
            'Описание конструкции',
            CoatingSystemDurability::LOW->value,
            CoatingSystemCorrosiveCategory::C1->value,
            CoatingSystemSurfaceTreatment::SA1->value,
            CoatingSystemApplicationMethod::AIR->value,
            30,
            [],
        );
        $this->em->flush();

        return $proposal;
    }

    private function authenticateAs(User $user): void
    {
        static::getContainer()->get(TokenStorageInterface::class)->setToken(
            new PreAuthenticatedToken($user, 'test', $user->getRoles())
        );
    }
}
