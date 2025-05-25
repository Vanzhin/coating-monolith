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
        $encodedUrl = preg_replace_callback(
            '/[^\x20-\x7f]/',
            function ($match) {
                return rawurlencode($match[0]);
            },
            $value
        );

        if (!filter_var($encodedUrl ?? $value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException(sprintf('Link is not a valid URL: %s.', $value));
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
