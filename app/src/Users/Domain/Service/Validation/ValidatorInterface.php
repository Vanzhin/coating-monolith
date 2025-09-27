<?php

declare(strict_types=1);

namespace App\Users\Domain\Service\Validation;

use Stringable;

interface ValidatorInterface
{
    public function isValid(string|Stringable $value): bool;
    
    public function supports(string|Stringable $value): bool;
}