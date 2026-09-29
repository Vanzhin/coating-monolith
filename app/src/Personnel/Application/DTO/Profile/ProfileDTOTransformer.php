<?php

declare(strict_types=1);

namespace App\Personnel\Application\DTO\Profile;

use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;

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
        $dto->lastName = $fullName->lastName->value;
        $dto->firstName = $fullName->firstName->value;
        $dto->middleName = $fullName->middleName?->value;
        $dto->positionId = $position->id;
        $dto->positionTitle = $position->title;
        $dto->organizationId = $organization->id;
        $dto->organizationTitle = $organization->title;
        $dto->departmentId = $department->id;
        $dto->departmentTitle = $department->title;
        $dto->personnelNumber = $profile->getPersonnelNumber();
        $dto->hiredAt = $profile->getHiredAt();
        $dto->clothing = self::sizeText($sizes->clothing);
        $dto->shoes = self::sizeText($sizes->shoes);
        $dto->headgear = self::sizeText($sizes->headgear);
        $dto->respirator = self::sizeText($sizes->respirator);
        $dto->gloves = self::sizeText($sizes->gloves);
        $dto->height = self::sizeText($sizes->height);
        $dto->gender = $sizes->gender?->value;

        return $dto;
    }

    /** Размер-число → строка для формы (null остаётся null). */
    private static function sizeText(?PositiveNumber $size): ?string
    {
        return null === $size ? null : (string) $size->value();
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
