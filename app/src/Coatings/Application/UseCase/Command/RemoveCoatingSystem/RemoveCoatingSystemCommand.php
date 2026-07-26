<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\RemoveCoatingSystem;

final readonly class RemoveCoatingSystemCommand
{
    public function __construct(
        public string $id,
    ) {
    }
}
