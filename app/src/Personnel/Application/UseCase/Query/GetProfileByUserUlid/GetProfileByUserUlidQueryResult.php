<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileByUserUlid;

use App\Personnel\Application\DTO\Profile\ProfileDTO;

final readonly class GetProfileByUserUlidQueryResult
{
    public function __construct(public ?ProfileDTO $profile)
    {
    }
}
