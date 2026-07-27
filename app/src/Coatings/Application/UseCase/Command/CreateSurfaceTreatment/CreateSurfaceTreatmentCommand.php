<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Command\Command;

final readonly class CreateSurfaceTreatmentCommand extends Command
{
    /**
     * @param list<Substrate> $substrateScope
     */
    public function __construct(
        public string $description,
        public ?string $code,
        public ?string $standardCode,
        public array $substrateScope,
    ) {
    }
}
