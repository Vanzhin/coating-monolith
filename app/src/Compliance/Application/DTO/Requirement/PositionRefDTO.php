<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Requirement;

/** Чип должности, к которой прикручено требование: id (для формы) + название (для показа/гидрации). */
final class PositionRefDTO
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
