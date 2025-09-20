<?php

declare(strict_types=1);

namespace App\Users\Application\DTO;

class TokenDTO
{
    public ?string $owner_id;
    public ?bool $expires_at;
}