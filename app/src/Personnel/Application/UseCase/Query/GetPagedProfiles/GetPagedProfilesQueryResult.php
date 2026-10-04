<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetPagedProfiles;

use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedProfilesQueryResult
{
    /**
     * @param list<ProfileDTO> $profiles
     */
    public function __construct(
        public array $profiles,
        public Pager $pager,
    ) {
    }
}
