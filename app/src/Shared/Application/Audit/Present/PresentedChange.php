<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present;

use App\Shared\Domain\Audit\ChangeOp;

/** Готовая для показа строка изменения: подпись + было/стало. */
final readonly class PresentedChange
{
    public function __construct(
        public ChangeOp $op,
        public string $label,
        public string $oldText,
        public string $newText,
    ) {
    }
}
