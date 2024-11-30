<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Coatings\Application\DTO\CoatingTags\CoatingTagDTO;
use App\Coatings\Application\DTO\Manufacturers\ManufacturerDTO;
use Symfony\Component\Validator\Constraints as Assert;


class CoatingMapper
{

    public function buildInputDataFromDto(CoatingDTO $coatingDTO): array
    {
        $manufacturerId = $coatingDTO->manufacturer->id;
        $coatingTagIds = array_map(function ($coatingTag) {
            return $coatingTag->id;
        }, $coatingDTO->tags);

        return array_merge(get_object_vars($coatingDTO), compact('manufacturerId', 'coatingTagIds'));
    }

    public function buildCoatingDtoFromInputData(array $inputData): CoatingDTO
    {
        $manufacturer = new ManufacturerDTO();
        $manufacturer->id = $inputData['manufacturer']['id'];

        $dto = new CoatingDTO();
        if ($inputData['id'] ?? null) {
            $dto->id = $inputData['id'];
        }
        $dto->title = $inputData['title'] ?? null;
        $dto->thinner = isset($inputData['thinner']) && strlen($inputData['thinner']) > 0 ? $inputData['thinner'] : null;
        $dto->description = $inputData['description'] ?? null;
        $dto->volumeSolid = (int)$inputData['volumeSolid'] ?? null;
        $dto->massDensity = (float)$inputData['massDensity'] ?? null;
        $dto->tdsDft = (int)$inputData['tdsDft'] ?? null;
        $dto->minDft = (int)$inputData['minDft'] ?? null;
        $dto->maxDft = (int)$inputData['maxDft'] ?? null;
        $dto->applicationMinTemp = (int)$inputData['applicationMinTemp'] ?? null;
        $dto->dryToTouch = (float)$inputData['dryToTouch'] ?? null;
        $dto->minRecoatingInterval = (float)$inputData['minRecoatingInterval'] ?? null;
        $dto->maxRecoatingInterval = (float)$inputData['maxRecoatingInterval'] ?? null;
        $dto->fullCure = (float)$inputData['fullCure'] ?? null;
        $dto->manufacturer = $manufacturer;
        $dto->pack = (float)$inputData['pack'] ?? null;

        $tags = [];
        foreach ($inputData['tags'] ?? [] as $tag) {
            $coatingTagDto = new CoatingTagDTO();
            $coatingTagDto->id = $tag['id'];
            $tags[] = $coatingTagDto;
        }
        $dto->tags = $tags;

        return $dto;
    }

    public function getValidationCollectionCoating(): Assert\Collection
    {
        return new Assert\Collection([
            'title' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3,
                    'max' => 100,
                    'maxMessage' => 'Название не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Название не должно быть короче {{ limit }}.',

                ]),
            ],
            'description' => [
                new Assert\NotBlank(),
                new Assert\Type('string'),
                new Assert\Length([
                    'min' => 3,
                    'max' => 750,
                    'maxMessage' => 'Описание не должно быть длиннее {{ limit }}.',
                    'minMessage' => 'Описание не должно быть короче {{ limit }}.',
                ]),
            ],
            'volumeSolid' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 10,
                    'max' => 100,
                    'notInRangeMessage' => 'Сухой остаток должен быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'massDensity' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 100,
                    'notInRangeMessage' => 'Плотность должна быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'tdsDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 10,
                    'max' => 9999,
                    'notInRangeMessage' => 'ТСП тех карты должна быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'minDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 10,
                    'max' => 9999,
                    'notInRangeMessage' => 'Мин ТСП должна быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'maxDft' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 10,
                    'max' => 9999,
                    'notInRangeMessage' => 'Макс ТСП должна быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'applicationMinTemp' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => -30,
                    'max' => 50,
                    'notInRangeMessage' => 'Мин Т нанесения должна быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'dryToTouch' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 100,
                    'notInRangeMessage' => 'Время "сухой на отлип" должно быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'minRecoatingInterval' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 100,
                    'notInRangeMessage' => 'Мин интервал перекрытия должен быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'maxRecoatingInterval' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 100,
                    'notInRangeMessage' => 'Макс интервал перекрытия должен быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],

            'fullCure' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 0,
                    'max' => 1000,
                    'notInRangeMessage' => 'Время полного отверждения должно быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],
            'pack' => [
                new Assert\NotBlank(),
                new Assert\Type('numeric'),
                new Assert\Range([
                    'min' => 1,
                    'max' => 1000,
                    'notInRangeMessage' => 'Время полного отверждения должно быть в переделах от {{ min }} до {{ max }}.'
                ]),
            ],

            'manufacturer' => new Assert\Collection([
                'id' =>
                    [
                        new Assert\NotBlank(),
                        new Assert\Uuid(),
                    ],
                'title' => new Assert\Optional(new Assert\Type('string')),
                'description' => new Assert\Optional(new Assert\Type('string')),

            ]),
            'tags' => new Assert\Optional([
                new Assert\All(
                    new Assert\Collection([
                        'id' =>
                            [
                                new Assert\NotBlank(),
                                new Assert\Uuid(),
                            ],
                        'title' => new Assert\Optional(new Assert\Type('string')),
                        'type' => new Assert\Optional(new Assert\Type('string')),
                    ]),
                ),
            ])
        ],
            allowExtraFields: true);
    }

}