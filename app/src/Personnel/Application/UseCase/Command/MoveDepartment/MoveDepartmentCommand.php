<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\MoveDepartment;

use App\Shared\Application\Command\Command;

final readonly class MoveDepartmentCommand extends Command
{
    public function __construct(
        public string $id,
        public ?string $parentId,
    ) {
    }
}
