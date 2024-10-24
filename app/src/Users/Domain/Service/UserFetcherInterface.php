<?php

declare(strict_types=1);

namespace App\Users\Domain\Service;

use App\Users\Domain\Entity\User;

interface UserFetcherInterface
{
    public function getUserById(string $userId): User;
}
