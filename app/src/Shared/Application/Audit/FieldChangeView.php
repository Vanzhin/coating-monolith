<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use App\Shared\Domain\Audit\ChangeOp;

/** Готовая для показа строка изменения: подпись + было/стало. Для add oldText='', для remove newText=''. */
final readonly class FieldChangeView
{
    public function __construct(
        public ChangeOp $op,
        public string $label,
        public string $oldText,
        public string $newText,
    ) {
    }
}
