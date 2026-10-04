<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\SuggestProfiles;

use App\Personnel\Application\DTO\Profile\ProfileDTO;

final readonly class SuggestProfilesQueryResult
{
    /**
     * @param list<ProfileDTO> $profiles
     */
    public function __construct(public array $profiles)
    {
    }
}
