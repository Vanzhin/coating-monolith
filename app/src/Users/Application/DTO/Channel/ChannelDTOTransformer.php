<?php

declare(strict_types=1);

namespace App\Users\Application\DTO\Channel;

use App\Users\Domain\Entity\Channel;

class ChannelDTOTransformer
{
    public function fromEntity(Channel $channel): ChannelDTO
    {
        $dto = new ChannelDTO($channel->getId(), $channel->getType()->value, $channel->getValue(), $channel->getOwner()->getId());
        $dto->is_verified = $channel->isVerified();
        $dto->verified_at = $channel->getVerifiedAt()->format(DATE_ATOM);

        return $dto;
    }

    public function fromEntityList(array $channels): array
    {
        $channelDTOs = [];
        foreach ($channels as $channel) {
            $channelDTOs[] = $this->fromEntity($channel);
        }

        return $channelDTOs;
    }
}