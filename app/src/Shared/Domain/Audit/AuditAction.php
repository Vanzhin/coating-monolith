<?php
declare(strict_types=1);
namespace App\Shared\Domain\Audit;

enum AuditAction: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
