<?php
declare(strict_types=1);


namespace App\Coatings\Infrastructure\Mapper;

use Symfony\Component\Validator\Constraints as Assert;


class CoatingMapper
{
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
                    'max' => 100,
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

            'manufacturerId' => [
                new Assert\NotBlank(),
                new Assert\Uuid(),
            ],
            'coatingTagIds' => new Assert\Optional([
                new Assert\All([
                    new Assert\NotBlank(),
                    new Assert\Uuid(),
                ])

            ]),
        ],
            allowExtraFields: true);
    }

}