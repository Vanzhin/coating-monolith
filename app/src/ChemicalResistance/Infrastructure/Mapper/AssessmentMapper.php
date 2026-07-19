<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Infrastructure\Mapper;

use Symfony\Component\Validator\Constraints as Assert;

final class AssessmentMapper
{
    private const GRADES = ['R', 'LR', 'NR', 'FS', 'NT'];

    /**
     * Структурная валидация формы «Добавить оценку». Бизнес-правила
     * (например, положительность температуры) — на стороне домена,
     * долетают до пользователя через AppException → flash-баннер.
     */
    public function getValidationCollectionCreate(): Assert\Collection
    {
        return new Assert\Collection([
            'fields' => [
                'substanceId' => [
                    new Assert\NotBlank(message: 'Выберите вещество.'),
                    new Assert\Type('string'),
                    new Assert\Uuid(message: 'Некорректный идентификатор вещества.'),
                ],
                'grade' => [
                    new Assert\NotBlank(message: 'Укажите оценку.'),
                    new Assert\Choice(choices: self::GRADES, message: 'Недопустимая оценка.'),
                ],
                'maxTemperatureCelsius' => new Assert\Optional([
                    new Assert\AtLeastOneOf([
                        new Assert\Blank(),
                        new Assert\Sequentially([
                            new Assert\Type('numeric'),
                            new Assert\Range(min: 1, max: 500, notInRangeMessage: 'Температура должна быть от {{ min }} до {{ max }} °C.'),
                        ]),
                    ], includeInternalMessages: false, message: 'Некорректная максимальная температура.'),
                ]),
                'noteIds' => new Assert\Optional([
                    new Assert\Type('array'),
                    new Assert\All([
                        new Assert\Uuid(message: 'Некорректный идентификатор примечания.'),
                    ]),
                ]),
            ],
            'allowExtraFields' => true,
        ]);
    }

    /**
     * Валидация формы «Редактировать оценку»: substance неизменен
     * (прибит гвоздями к assessment), остальное как в create.
     */
    public function getValidationCollectionUpdate(): Assert\Collection
    {
        $create = $this->getValidationCollectionCreate();
        $fields = $create->fields;
        unset($fields['substanceId']);

        return new Assert\Collection([
            'fields' => $fields,
            'allowExtraFields' => true,
        ]);
    }
}
