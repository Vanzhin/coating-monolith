<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RemoveFulfillment;

use App\Shared\Application\Command\Command;

readonly class RemoveFulfillmentCommand extends Command
{
    public function __construct(
        public string $profileId,
        public string $recordId,
    ) {
    }
}
