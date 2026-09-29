<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\UpdateDepartment;

final readonly class UpdateDepartmentCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
