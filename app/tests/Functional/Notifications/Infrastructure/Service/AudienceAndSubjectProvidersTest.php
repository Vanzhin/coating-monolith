<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Infrastructure\Service;

use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Infrastructure\Service\PersonnelSubjectContextProvider;
use App\Shared\Application\Query\QueryBusInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use App\Users\Infrastructure\Service\UsersAudienceProvider;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Провайдеры-реализации портов уведомлений в контекстах-владельцах (Users/Personnel). Инстанцируем напрямую с
 * зависимостями из контейнера: DI-алиасы портов до появления потребителя (резолверы, Task 5) контейнер вырезает
 * как неиспользуемые — корректность проводки проверит Task 5 и финальный ./run check.
 */
final class AudienceAndSubjectProvidersTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_admin_ulids_and_subject_resolve(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $admin = $this->makeUser($c, $em, ['ROLE_ADMIN']);
        $this->makeUser($c, $em, []); // обычный — не попадёт в adminUlids

        $audience = new UsersAudienceProvider($c->get(UserRepositoryInterface::class));
        self::assertContains($admin->getUlid(), $audience->adminUlids());

        ['profileId' => $profileId] = $this->enrollCompliance();
        $subject = new PersonnelSubjectContextProvider(
            $c->get(QueryBusInterface::class),
            $c->get(DepartmentRepositoryInterface::class),
        );
        self::assertNotNull($subject->userUlidOfProfile($profileId), 'профиль→userUlid резолвится');
    }

    /** @param list<string> $roles */
    private function makeUser(ContainerInterface $c, EntityManagerInterface $em, array $roles): User
    {
        $u = new User(new Email('aud_'.uniqid('', true).'@example.com'));
        $u->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($u, 'isActive'))->setValue($u, true);
        if ([] !== $roles) {
            (new \ReflectionProperty($u, 'roles'))->setValue($u, $roles);
        }
        $em->persist($u);
        $em->flush();

        return $u;
    }
}
