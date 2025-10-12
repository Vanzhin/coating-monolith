<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service;

class Token
{
    public function __construct(
        private readonly string $token,
        private readonly \DateInterval $remainingTime
    ) {
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getRemainingTime(): \DateInterval
    {
        return $this->remainingTime;
    }
}
