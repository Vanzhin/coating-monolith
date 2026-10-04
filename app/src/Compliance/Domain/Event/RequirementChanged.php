<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/** Требование создано/изменено — проекции учёта покрытых людей надо пересобрать. */
final readonly class RequirementChanged implements EventInterface
{
    public function __construct(public string $requirementId)
    {
    }
}
