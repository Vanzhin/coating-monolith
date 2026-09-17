<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\SearchUsers;

use App\Users\Application\DTO\UserSuggestDTO;

readonly class SearchUsersQueryResult
{
    /**
     * @param list<UserSuggestDTO> $users
     */
    public function __construct(public array $users)
    {
    }
}
