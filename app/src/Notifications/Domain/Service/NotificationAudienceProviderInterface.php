<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Service;

/** Порт в контекст Users: аудитория для резолва адресатов (админы / все пользователи). */
interface NotificationAudienceProviderInterface
{
    /** @return list<string> ULID пользователей с ROLE_ADMIN. */
    public function adminUlids(): array;

    /** @return list<string> ULID всех пользователей (для broadcast). */
    public function allUserUlids(): array;

    /** Есть ли живой пользователь с таким ulid (адресат мог прийти из осиротевшей ссылки — profile/отдел). */
    public function existsUser(string $ulid): bool;
}
