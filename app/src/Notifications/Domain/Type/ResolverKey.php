<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Type;

/** Стратегия резолва адресатов события. */
enum ResolverKey: string
{
    case Owner = 'owner';
    case SubjectSupervisors = 'subject_supervisors';
    case Broadcast = 'broadcast';
}
