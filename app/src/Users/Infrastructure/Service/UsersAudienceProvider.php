<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Service;

use App\Notifications\Domain\Service\NotificationAudienceProviderInterface;
use App\Shared\Domain\Repository\Pager;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Repository\UsersFilter;

/**
 * Реализация порта аудитории уведомлений на стороне Users (владелец данных о пользователях/ролях).
 * Внутренний инструмент — пользователей немного, тянем одной большой страницей и фильтруем по роли в PHP.
 */
final readonly class UsersAudienceProvider implements NotificationAudienceProviderInterface
{
    public function __construct(private UserRepositoryInterface $users)
    {
    }

    public function adminUlids(): array
    {
        $ulids = [];
        foreach ($this->allUsers() as $user) {
            if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                $ulids[] = $user->getUlid();
            }
        }

        return $ulids;
    }

    public function allUserUlids(): array
    {
        return array_values(array_map(static fn (User $u): string => $u->getUlid(), $this->allUsers()));
    }

    public function existsUser(string $ulid): bool
    {
        return null !== $this->users->getByUlid($ulid);
    }

    /** @return list<User> */
    private function allUsers(): array
    {
        /** @var list<User> $items */
        $items = $this->users->findByFilter(new UsersFilter(Pager::fromPage(1, 1000)))->items;

        return $items;
    }
}
