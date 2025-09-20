<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity;

final readonly class Token
{
    public function __construct(
        private string $token,
        private \DateTimeImmutable $expiresAt,
    ) {
        if ($expiresAt <= new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Expiration date must be in the future');
        }
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isValid(): bool
    {
        return $this->expiresAt > new \DateTimeImmutable();
    }

    public function equals(string $code): bool
    {
        return hash_equals($this->token, $code);
    }

    public function getRemainingTime(): \DateInterval
    {
        return $this->expiresAt->diff(new \DateTimeImmutable());
    }

    public function __toString(): string
    {
        return $this->token;
    }

}