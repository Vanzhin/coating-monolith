<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

use Psr\Log\InvalidArgumentException;

class Link implements \Stringable
{
    private string $value;

    public function __construct(string $value)
    {
        $this->assertValidName($value);
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    private function assertValidName(string $value): void
    {
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('News link is not a valid URL.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
