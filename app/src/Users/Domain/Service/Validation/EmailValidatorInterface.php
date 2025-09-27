<?php

declare(strict_types=1);

namespace App\Users\Domain\Service\Validation;

use App\Users\Domain\Entity\ValueObject\Email;

interface EmailValidatorInterface extends ValidatorInterface
{
    public function isEmailValid(Email $value): bool;
}