<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\SearchUsers;

use App\Shared\Application\Query\Query;
use App\Users\Domain\Repository\UsersFilter;

/**
 * Постраничный поиск юзеров для typeahead (фасет «Актор» админ-журнала, пикер юзера в форме профиля) —
 * через единый UsersFilter. Лёгкий UserSuggestDTO вместо полного профиля.
 */
readonly class SearchUsersQuery extends Query
{
    public function __construct(public UsersFilter $filter)
    {
    }
}
