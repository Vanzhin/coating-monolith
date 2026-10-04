<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreateDepartment;

use App\Shared\Application\Command\Command;

final readonly class CreateDepartmentCommand extends Command
{
    public function __construct(
        public string $companyId,
        public string $title,
        public ?string $parentId = null,
        public ?string $headUserUlid = null,
    ) {
    }
}
