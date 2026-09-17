<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Infrastructure\Audit;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Security\SystemUser;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Infrastructure\Audit\UserActorResolver;
use PHPUnit\Framework\TestCase;

final class UserActorResolverTest extends TestCase
{
    public function test_system_id_resolves_to_system_label(): void
    {
        $resolver = new UserActorResolver($this->repository([]));

        self::assertSame('Система', $resolver->resolve(SystemUser::ID));
    }

    public function test_known_ulid_resolves_to_email(): void
    {
        $user = new User(new Email('known@example.com'));
        $resolver = new UserActorResolver($this->repository([$user->getUlid() => $user]));

        self::assertSame('known@example.com', $resolver->resolve($user->getUlid()));
    }

    public function test_unknown_ulid_falls_back_to_raw_id(): void
    {
        $resolver = new UserActorResolver($this->repository([]));

        self::assertSame('unknown-ulid', $resolver->resolve('unknown-ulid'));
    }

    /**
     * @param array<string, User> $byUlid
     */
    private function repository(array $byUlid): UserRepositoryInterface
    {
        return new class($byUlid) implements UserRepositoryInterface {
            /** @param array<string, User> $byUlid */
            public function __construct(private array $byUlid)
            {
            }

            public function add(User $user): void
            {
            }

            public function getByUlid(string $ulid): ?User
            {
                return $this->byUlid[$ulid] ?? null;
            }

            public function getByEmail(string $email): ?User
            {
                return null;
            }

            public function searchByEmail(string $query, int $limit): array
            {
                return [];
            }

            public function findByIds(StringCollection $ids): array
            {
                return [];
            }
        };
    }
}
