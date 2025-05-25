<?php

declare(strict_types=1);

namespace App\Documents\Domain\Aggregate\Document\ValueObject;

use Webmozart\Assert\Assert;

class DocumentTitle implements \Stringable
{
    private const MIN_LENGTH = 3;
    private const MAX_LENGTH = 512;
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
        Assert::lengthBetween(
            $value,
            min: self::MIN_LENGTH,
            max: self::MAX_LENGTH,
            message: sprintf(
                'Document lenght must be within %s and %s characters.',
                self::MIN_LENGTH,
                self::MAX_LENGTH
            )
        );
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
