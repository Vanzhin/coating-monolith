<?php

declare(strict_types=1);

namespace App\Users\Domain\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;
use App\Users\Domain\Entity\User;

interface UserRepositoryInterface
{
    public function add(User $user): void;

    public function getByUlid(string $ulid): ?User;

    public function getByEmail(string $email): ?User;

    /**
     * Единый постраничный поиск/список пользователей через фильтр (typeahead админ-журнала и формы).
     * Расширяется полями UsersFilter (email), а не новыми методами.
     */
    public function findByFilter(UsersFilter $filter): PaginationResult;

    /**
     * Гидрация чипов фасета «Актор» по списку ulid (shareable-ссылка в URL несёт
     * только id, email дотягивается отсюда).
     *
     * @return User[]
     */
    public function findByIds(StringCollection $ids): array;
}
