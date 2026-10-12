<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Service;

/** Порт в контекст Personnel: контекст субъекта-сотрудника (его юзер). */
interface SubjectContextProviderInterface
{
    public function userUlidOfProfile(string $profileId): ?string;
}
