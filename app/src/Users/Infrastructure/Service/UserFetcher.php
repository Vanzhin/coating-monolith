<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Service;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Service\UserFetcherInterface;
use Webmozart\Assert\Assert;

readonly class UserFetcher implements UserFetcherInterface
{
    public function __construct(private UserRepositoryInterface $userRepository)
    {
    }

    public function getUserById(string $userId): User
    {
        $user = $this->userRepository->getByUlid($userId);
        Assert::notNull($user, 'No user found.');

        return $user;
    }
}
