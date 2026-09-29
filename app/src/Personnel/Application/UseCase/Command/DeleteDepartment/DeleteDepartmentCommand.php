<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\DeleteDepartment;

use App\Shared\Application\Command\Command;

final readonly class DeleteDepartmentCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
