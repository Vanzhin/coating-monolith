<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\SearchUsers;

use App\Shared\Domain\Repository\Pager;
use App\Users\Application\DTO\UserSuggestDTO;

readonly class SearchUsersQueryResult
{
    /**
     * @param list<UserSuggestDTO> $users
     */
    public function __construct(public array $users, public Pager $pager)
    {
    }
}
