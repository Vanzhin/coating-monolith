<?php

declare(strict_types=1);

namespace App\Users\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

/**
 * Bag-of-fields поиска пользователей (typeahead/список). Расширяется полями, а не новыми методами
 * репозитория. email — поиск по подстроке почты; hasProfile — есть ли у юзера профиль сотрудника
 * (false → только без профиля, для пикера привязки; null → без фильтра).
 */
class UsersFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?string $email = null,
        public ?bool $hasProfile = null,
    ) {
    }
}
