<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Query\GetUserIdByEmail;

use App\Shared\Application\Query\Query;

/**
 * Резолв пользователя по email → его ulid (или null). Для внешних контекстов (почтовый адаптер
 * Inquiries): сопоставить отправителя письма с пользователем системы через шину, не лазая в чужой репозиторий.
 */
final readonly class GetUserIdByEmailQuery extends Query
{
    public function __construct(public string $email)
    {
    }
}
