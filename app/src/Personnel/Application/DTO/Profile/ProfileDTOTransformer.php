<?php

declare(strict_types=1);

namespace App\Personnel\Application\DTO\Profile;

use App\Personnel\Domain\Aggregate\Profile\Profile;

class ProfileDTOTransformer
{
    public function fromEntity(Profile $profile): ProfileDTO
    {
        $fullName = $profile->getFullName();
        $position = $profile->getPosition();
        $organization = $profile->getOrganization();
        $department = $profile->getDepartment();
        $sizes = $profile->getSizes();

        $dto = new ProfileDTO();
        $dto->id = $profile->getId();
        $dto->userUlid = $profile->getUserUlid();
        $dto->lastName = $fullName->lastName;
        $dto->firstName = $fullName->firstName;
        $dto->middleName = $fullName->middleName;
        $dto->positionId = $position->id;
        $dto->positionTitle = $position->title;
        $dto->organizationId = $organization->id;
        $dto->organizationTitle = $organization->title;
        $dto->departmentId = $department->id;
        $dto->departmentTitle = $department->title;
        $dto->personnelNumber = $profile->getPersonnelNumber();
        $dto->hiredAt = $profile->getHiredAt();
        $dto->clothing = $sizes->clothing;
        $dto->shoes = $sizes->shoes;
        $dto->headgear = $sizes->headgear;
        $dto->gasMask = $sizes->gasMask;
        $dto->respirator = $sizes->respirator;
        $dto->gloves = $sizes->gloves;
        $dto->height = $sizes->height;
        $dto->gender = $sizes->gender?->value;

        return $dto;
    }

    /**
     * @param iterable<Profile> $profiles
     *
     * @return list<ProfileDTO>
     */
    public function fromEntityList(iterable $profiles): array
    {
        $dtos = [];
        foreach ($profiles as $profile) {
            $dtos[] = $this->fromEntity($profile);
        }

        return $dtos;
    }
}
