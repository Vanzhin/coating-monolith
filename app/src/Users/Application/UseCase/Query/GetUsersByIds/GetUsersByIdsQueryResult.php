<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\GetUsersByIds;

use App\Users\Application\DTO\UserSuggestDTO;

readonly class GetUsersByIdsQueryResult
{
    /**
     * @param list<UserSuggestDTO> $users
     */
    public function __construct(public array $users)
    {
    }
}
