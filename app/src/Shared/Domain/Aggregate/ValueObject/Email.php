<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\ValueObject;

class Email implements \Stringable
{
    protected readonly string $value;
    private string $localPart;
    private string $domain;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));
        $this->validateFormat($normalized);
        $this->value = $normalized;
        [$this->localPart, $this->domain] = explode('@', $normalized);
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    private function validateFormat(string $value): void
    {
        if (empty($value)) {
            throw new \InvalidArgumentException('Email не может быть пустым.');
        }

        if (false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException(sprintf('Не верный формат почты: "%s".', $value));
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getLocalPart(): string
    {
        return $this->localPart;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function equals(?self $other): bool
    {
        return $other instanceof self && $this->value === $other->value;
    }

    public function isSameDomain(self $other): bool
    {
        return $this->domain === $other->domain;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
