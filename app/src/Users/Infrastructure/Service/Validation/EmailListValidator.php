<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Service\Validation;

use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\Validation\EmailValidatorInterface;
use Stringable;

class EmailListValidator extends ListValidator implements EmailValidatorInterface
{
    protected function normalizeValue(string|Stringable $value): string
    {
        $stringValue = (string)$value;
        $normalized = strtolower(trim($stringValue));

        if (!filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email format');
        }

        return $normalized;
    }

    protected function isSupportedNormalized(string $normalizedValue): bool
    {
        return filter_var($normalizedValue, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function getValidationErrorMessage(string|Stringable $value): string
    {
        $stringValue = (string)$value;

        try {
            $normalized = $this->normalizeValue($value);

            if ($this->blacklistEnabled && $this->isInBlacklist($normalized)) {
                return sprintf('Email "%s" is blacklisted', $stringValue);
            }

            if ($this->whitelistEnabled && !$this->isInWhitelist($normalized)) {
                return sprintf('Email "%s" is not whitelisted', $stringValue);
            }
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return sprintf('Email "%s" is invalid', $stringValue);
    }

    public function isEmailValid(Email $value): bool
    {
        return parent::isValid($value->getValue());
    }
}