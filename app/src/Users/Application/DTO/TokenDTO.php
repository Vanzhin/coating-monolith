<?php

declare(strict_types=1);

namespace App\Users\Application\DTO;

class TokenDTO
{
    public function __construct(
        public string $token,
        public string $subjectId,
        public string $expiresAt
    ) {
    }
}