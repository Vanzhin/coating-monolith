<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\Security\AuthUserInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Webmozart\Assert\Assert;

readonly class AuthUserFetcher implements AuthUserFetcherInterface
{
    public function __construct(private Security $security)
    {
    }

    public function getAuthUser(): AuthUserInterface
    {
        /** @var AuthUserInterface $user */
        $user = $this->security->getUser();

        Assert::notNull($user, 'Current user not found.');
        Assert::isInstanceOf($user, AuthUserInterface::class, 'Invalid user type.');

        return $user;
    }

    public function getAuthUserId(): string
    {
        return $this->getAuthUser()->getUlid();
    }
}
