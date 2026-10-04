<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\UpdateDepartment;

use App\Shared\Application\Command\Command;

final readonly class UpdateDepartmentCommand extends Command
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
