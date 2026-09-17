<?php
declare(strict_types=1);
namespace App\Shared\Domain\Audit;

enum ChangeOp: string
{
    case Set = 'set';
    case Add = 'add';
    case Remove = 'remove';
}
