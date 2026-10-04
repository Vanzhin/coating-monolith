<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreateDepartment;

final readonly class CreateDepartmentCommandResult
{
    public function __construct(
        public string $id,
        public string $title,
        public string $companyId,
    ) {
    }
}
