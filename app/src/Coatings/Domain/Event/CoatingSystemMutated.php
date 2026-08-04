<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

final readonly class CoatingSystemMutated implements EventInterface
{
    public function __construct(public string $systemId)
    {
    }
}
