<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use App\Shared\Domain\Audit\ChangeOp;

final readonly class FieldChangeView
{
    public function __construct(
        public ChangeOp $op,
        public string $label,
        public string $path,
        public mixed $old,
        public mixed $new,
    ) {
    }
}
