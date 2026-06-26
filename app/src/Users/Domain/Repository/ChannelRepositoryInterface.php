<?php

declare(strict_types=1);

namespace App\Users\Domain\Repository;

use App\Shared\Domain\Repository\PaginationResult;
use App\Users\Domain\Entity\Channel;

interface ChannelRepositoryInterface
{
    public function add(Channel $channel): void;

    public function remove(Channel $channel): void;

    public function findById(string $id): ?Channel;

    public function findByFilter(ChannelFilter $filter): PaginationResult;

    /**
     * Точное соответствие (owner_id, type, value) — используется для идемпотентного создания
     * email-канала после регистрации (см. ChannelVerificationAction). Symfony Security держит
     * User в session с stale-коллекцией channels, поэтому $user->getChannels()->isEmpty()
     * может врать; этот метод спрашивает БД напрямую.
     */
    public function findOneByOwnerTypeValue(string $ownerId, string $type, string $value): ?Channel;
}
