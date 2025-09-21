<?php

declare(strict_types=1);

namespace App\Users\Domain\Repository;

use App\Users\Domain\Entity\Channel;

interface ChannelRepositoryInterface
{
    public function add(Channel $channel): void;

    public function remove(Channel $channel): void;

    public function findById(string $id): ?Channel;
}
