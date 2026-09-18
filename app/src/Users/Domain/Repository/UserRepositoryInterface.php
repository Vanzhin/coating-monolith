<?php

declare(strict_types=1);

namespace App\Users\Domain\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Users\Domain\Entity\User;

interface UserRepositoryInterface
{
    public function add(User $user): void;

    public function getByUlid(string $ulid): ?User;

    public function getByEmail(string $email): ?User;

    /**
     * Постраничный typeahead по email: регистронезависимая частичная подстрока,
     * упорядочено по email. Кормит suggest-эндпоинт админ-журнала.
     *
     * @return User[]
     */
    public function searchByEmail(string $query, int $limit): array;

    /**
     * Гидрация чипов фасета «Актор» по списку ulid (shareable-ссылка в URL несёт
     * только id, email дотягивается отсюда).
     *
     * @return User[]
     */
    public function findByIds(StringCollection $ids): array;
}
