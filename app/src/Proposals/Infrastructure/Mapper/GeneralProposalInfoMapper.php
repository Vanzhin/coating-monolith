<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTO;
use App\Proposals\Application\DTO\GeneralProposalInfoItem\GeneralProposalInfoItemDTO;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemApplicationMethod;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemCorrosiveCategory;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemDurability;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemSurfaceTreatment;
use Symfony\Component\Validator\Constraints as Assert;


class GeneralProposalInfoMapper
{

    public function buildInputDataFromDto(CoatingDTO $coatingDTO): array
    {
        $manufacturerId = $coatingDTO->manufacturer->id;
        $coatingTagIds = array_map(function ($coatingTag) {
            return $coatingTag->id;
        }, $coatingDTO->tags);

        return array_merge(get_object_vars($coatingDTO), compact('manufacturerId', 'coatingTagIds'));
    }

    public function buildDtoFromInputData(array $inputData): GeneralProposalInfoDTO
    {
        $dto = new GeneralProposalInfoDTO();
        //
        $dto->number = $inputData['number'];
        $dto->ownerId = $inputData['ownerId'];
        $dto->description = $inputData['description'] ?? null;
        $dto->basis = $inputData['basis'] ?? null;
        $dto->projectArea = (float)$inputData['projectArea'];
        $dto->loss = (int)$inputData['loss'];
        $dto->projectTitle = $inputData['projectTitle'] ?? null;
        $dto->projectStructureDescription = $inputData['projectStructureDescription'] ?? null;
        $dto->durability = $inputData['durability'];
        $dto->treatment = $inputData['treatment'];
        $dto->category = $inputData['category'];
        $dto->method = $inputData['method'];
        $dto->unit = $inputData['unit'];
        $coats = [];
        foreach ($inputData['coats'] ?? [] as $coat) {
            $itemDto = new GeneralProposalInfoItemDTO();
            $itemDto->coatId = $coat['coatId'];
            $itemDto->loss = empty($coat['loss']) ? null : (int)$coat['loss'];
            $itemDto->coatPrice = (float)$coat['coatPrice'];
            $itemDto->coatNumber = (int)$coat['coatNumber'];
            $itemDto->coatDft = (int)$coat['coatDft'];
            $itemDto->coatColor = $coat['coatColor'];
            $itemDto->thinnerPrice = (int)$coat['thinnerPrice'];
            $itemDto->thinnerConsumption = (int)$coat['thinnerConsumption'];
            $coats[] = $itemDto;
        }
        $dto->coats = $coats;

        return $dto;
    }

    public function getValidationCollectionGeneralProposalInfo(): Assert\Collection
    {
        return new Assert\Collection([
            'number' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 1,
                    'max' => 100,
                    'maxMessage' => 'Номер не должен быть длиннее {{ limit }}.',
                    'minMessage' => 'Номер не должен быть короче {{ limit }}.',

                ]),
            ],
            'description' => new Assert\Optional([
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3,
                    'max' => 750,
                    'maxMessage' => 'Описание не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Описание не должно быть короче {{ limit }}.',
                ]),
            ]),
            'basis' => new Assert\Optional([
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3,
                    'max' => 750,
                    'maxMessage' => 'Основание не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Основание не должно быть короче {{ limit }}.',
                ]),
            ]),
            'projectArea' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 1,
                    'max' => 1000000,
                    'notInRangeMessage' => 'Площадь должна быть в переделах от {{ min }} до {{ max }} м кв.'
                ]),
            ],
            'loss' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 99,
                    'notInRangeMessage' => 'Потери должны быть в переделах от {{ min }} до {{ max }} %.'
                ]),
            ],
            'projectTitle' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3,
                    'max' => 750,
                    'maxMessage' => 'Название проекта не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Название проекта не должно быть короче {{ limit }}.',
                ]),
            ],
            'projectStructureDescription' => new Assert\Optional([
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3,
                    'max' => 750,
                    'maxMessage' => 'Описание конструкций проекта не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Описание конструкций проекта не должно быть короче {{ limit }}.',
                ]),
            ]),
            'durability' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Choice(CoatingSystemDurability::values(),
                    message: sprintf('Не верный формат для долговечности. Поддерживается: %s.',
                        implode(', ', CoatingSystemDurability::values())))
            ],
            'category' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Choice(CoatingSystemCorrosiveCategory::values(),
                    message: sprintf('Не верный формат коррозионной категории. Поддерживается: %s.',
                        implode(', ', CoatingSystemCorrosiveCategory::values())))
            ],
            'treatment' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Choice(CoatingSystemSurfaceTreatment::values(),
                    message: sprintf('Не верный формат подготовки поверхности. Поддерживается: %s.',
                        implode(', ', CoatingSystemSurfaceTreatment::values())))
            ],
            'method' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Choice(CoatingSystemApplicationMethod::values(),
                    message: sprintf('Не верный формат метода нанесения. Поддерживается: %s.',
                        implode(', ', CoatingSystemApplicationMethod::values())))
            ],

            'coats' => new Assert\All([
                new Assert\Collection([
                    'coatId' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                    ],
                    'coatPrice' => [
                        new Assert\NotBlank(),
                        new Assert\Type('numeric'),
                        new Assert\Positive(
                            message: 'Цена покрытия должна быть положительным числом.'
                        )
                    ],
                    'coatNumber' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                    ],
                    'coatDft' => [
                        new Assert\NotBlank(),
                        new Assert\Type('numeric'),
                        new Assert\Range([
                            'min' => 10,
                            'max' => 9999,
                            'notInRangeMessage' => 'ТСП должна быть в переделах от {{ min }} до {{ max }}.'
                        ]),
                    ],
                    'coatColor' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                        new Assert\Length([
                            'min' => 3,
                            'max' => 100,
                            'maxMessage' => 'Название цвета покрытия не должно быть длиннее {{ limit }}.',
                            'minMessage' => 'Название цвета покрытия не должно быть короче {{ limit }}.',
                        ]),
                    ],
                    'thinnerPrice' => [
                        new Assert\NotBlank(),
                        new Assert\Type('numeric'),
                        new Assert\Positive(
                            message: 'Цена растворителя должна быть положительным числом.'
                        )
                    ],
                    'loss' => new Assert\Optional([
                        new Assert\AtLeastOneOf([
                            new Assert\Blank(),
                            new Assert\Type('numeric'),
                            new Assert\Range([
                                'min' => 0,
                                'max' => 99,
                                'notInRangeMessage' => 'Потери должны быть в переделах от {{ min }} до {{ max }} %.'
                            ]),
                        ])
                    ]),
                    'thinnerConsumption' => [
                        new Assert\NotBlank(),
                        new Assert\Type('numeric'),
                        new Assert\Range([
                            'min' => 0,
                            'max' => 99,
                            'notInRangeMessage' => 'Процент разбавления должен быть в переделах от {{ min }} до {{ max }} %.'
                        ]),
                    ],

                ]),

            ]),
        ],
            allowExtraFields: true);
    }

}