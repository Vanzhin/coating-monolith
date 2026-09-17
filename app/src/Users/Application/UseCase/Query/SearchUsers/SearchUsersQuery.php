<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\SearchUsers;

use App\Shared\Application\Query\Query;

/**
 * Лёгкий поиск юзеров по email для typeahead (фасет «Актор» в админ-журнале).
 * Один-в-один с Coatings\SearchCoatingsQuery по назначению — только легковесный
 * UserSuggestDTO вместо полного профиля.
 */
readonly class SearchUsersQuery extends Query
{
    public function __construct(public string $q, public int $limit)
    {
    }
}
