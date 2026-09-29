<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\AssignDepartmentHead;

use App\Shared\Application\Command\Command;

final readonly class AssignDepartmentHeadCommand extends Command
{
    public function __construct(
        public string $id,
        public ?string $headUserUlid,
    ) {
    }
}
