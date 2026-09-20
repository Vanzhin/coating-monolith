<?php

declare(strict_types=1);

namespace App\Reports\Domain\Hint;

/** Одна подсказка: машинный код + человекочитаемое сообщение для фронта. */
final readonly class LayerWarning
{
    public function __construct(
        public LayerWarningCode $code,
        public string $message,
    ) {
    }
}
