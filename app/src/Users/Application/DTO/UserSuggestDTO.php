<?php

declare(strict_types=1);

namespace App\Users\Application\DTO;

/**
 * Лёгкий DTO строки typeahead-подсказки юзера. Только то, что нужно выпадающему
 * списку актора в админ-журнале: id (ulid) и готовый к показу title (email).
 * Без тяжёлых связей (роли/каналы) — их несёт полноценный UserDTO, которого
 * здесь нет и не нужно.
 */
final readonly class UserSuggestDTO
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
