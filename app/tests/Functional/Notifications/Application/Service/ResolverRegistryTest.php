<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notifications\Application\Service;

use App\Notifications\Application\Service\Resolver\BroadcastResolver;
use App\Notifications\Application\Service\Resolver\OwnerResolver;
use App\Notifications\Application\Service\Resolver\ResolverRegistry;
use App\Notifications\Application\Service\Resolver\SubjectSupervisorsResolver;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\OwnedNotification;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Type\NotificationType;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Infrastructure\Service\PersonnelSubjectContextProvider;
use App\Shared\Application\Query\QueryBusInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Infrastructure\Service\UsersAudienceProvider;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Реестр резолверов адресатов. Собираем его напрямую из резолверов (с провайдерами из контейнера): реестр —
 * dangling-сервис до диспетчера (Task 8), контейнер его вырезает; корректность DI-тега проверит ./run check.
 */
final class ResolverRegistryTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_owner_resolver_targets_only_owner(): void
    {
        self::bootKernel();
        $reg = $this->registry(self::getContainer());
        $e = new class implements NotifiableEvent, OwnedNotification {
            public function notificationType(): NotificationType
            {
                return NotificationType::UserActivated;
            }

            public function ownerUlid(): string
            {
                return 'u-owner';
            }
        };
        self::assertSame(['u-owner'], $reg->resolve($e));
    }

    public function test_subject_supervisors_includes_subject_not_strangers(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        ['profileId' => $profileId] = $this->enrollCompliance();
        $reg = $this->registry(self::getContainer());
        $e = new class($profileId) implements NotifiableEvent, SubjectNotification {
            public function __construct(private string $p)
            {
            }

            public function notificationType(): NotificationType
            {
                return NotificationType::ComplianceDueSoon;
            }

            public function subjectProfileId(): string
            {
                return $this->p;
            }
        };
        $recipients = $reg->resolve($e);
        self::assertNotEmpty($recipients, 'есть хотя бы субъект/надзорные');
        self::assertNotContains('u-stranger', $recipients, 'чужой не попадает');
    }

    private function registry(ContainerInterface $c): ResolverRegistry
    {
        $audience = new UsersAudienceProvider($c->get(UserRepositoryInterface::class));
        $subject = new PersonnelSubjectContextProvider(
            $c->get(QueryBusInterface::class),
            $c->get(DepartmentRepositoryInterface::class),
        );

        return new ResolverRegistry([
            new OwnerResolver(),
            new SubjectSupervisorsResolver($subject, $audience),
            new BroadcastResolver($audience),
        ]);
    }
}
