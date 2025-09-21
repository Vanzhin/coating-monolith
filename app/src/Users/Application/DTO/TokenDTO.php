<?php

declare(strict_types=1);

namespace App\Users\Application\DTO;

class TokenDTO
{
    public string $token;
    public string $subjectId;
    public string $expiresAt;
}