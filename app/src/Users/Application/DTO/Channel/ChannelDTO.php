<?php

declare(strict_types=1);

namespace App\Users\Application\DTO\Channel;

class ChannelDTO
{
    public ?bool $is_verified = null;
    public ?string $verified_at = null;

    public function __construct(
        public ?string $id,
        public ?string $type,
        public ?string $value,
        public ?string $owner_id
    ) {
    }
}