<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

interface CoatingSystemChainValidatorInterface
{
    public function validate(CoatingSystem $system): void;
}
