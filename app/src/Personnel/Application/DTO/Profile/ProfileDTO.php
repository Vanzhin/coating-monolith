<?php

declare(strict_types=1);

namespace App\Personnel\Application\DTO\Profile;

class ProfileDTO
{
    public string $id;
    public string $userUlid;
    public string $lastName;
    public string $firstName;
    public ?string $middleName = null;
    public string $positionId;
    public string $positionTitle;
    public string $organizationId;
    public string $organizationTitle;
    public string $departmentId;
    public string $departmentTitle;
    public ?string $personnelNumber = null;
    public ?\DateTimeImmutable $hiredAt = null;
    public ?string $clothing = null;
    public ?string $shoes = null;
    public ?string $headgear = null;
    public ?string $respirator = null;
    public ?string $gloves = null;
    public ?string $height = null;
    public ?GenderDTO $gender = null;
}
