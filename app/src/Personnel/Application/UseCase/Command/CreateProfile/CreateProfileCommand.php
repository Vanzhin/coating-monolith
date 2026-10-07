<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreateProfile;

use App\Shared\Application\Command\Command;

final readonly class CreateProfileCommand extends Command
{
    public function __construct(
        public string $userUlid,
        public string $lastName,
        public string $firstName,
        public ?string $middleName,
        public string $positionId,
        public string $organizationId,
        public string $departmentId,
        public ?string $personnelNumber,
        public ?\DateTimeImmutable $hiredAt,
        public ?string $clothing,
        public ?string $shoes,
        public ?string $headgear,
        public ?string $respirator,
        public ?string $gloves,
        public ?string $height,
        public ?string $gender,
        public ?\DateTimeImmutable $birthDate = null,
    ) {
    }
}
