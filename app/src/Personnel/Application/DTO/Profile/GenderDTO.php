<?php

declare(strict_types=1);

namespace App\Personnel\Application\DTO\Profile;

/** Пол сотрудника для чтения/документов: код (value — для сверки в форме) + русская подпись (label). */
final readonly class GenderDTO
{
    public function __construct(
        public string $value,
        public string $label,
    ) {
    }
}
