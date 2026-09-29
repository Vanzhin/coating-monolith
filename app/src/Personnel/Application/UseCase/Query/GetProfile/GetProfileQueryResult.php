<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfile;

use App\Personnel\Application\DTO\Profile\ProfileDTO;

final readonly class GetProfileQueryResult
{
    public function __construct(public ?ProfileDTO $profile)
    {
    }
}
