<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfilesByIds;

use App\Personnel\Application\DTO\Profile\ProfileDTO;

final readonly class GetProfilesByIdsQueryResult
{
    /**
     * @param list<ProfileDTO> $profiles
     */
    public function __construct(public array $profiles)
    {
    }
}
