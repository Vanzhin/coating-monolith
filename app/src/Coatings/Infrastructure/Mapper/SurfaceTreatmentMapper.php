<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\SurfaceTreatments\SurfaceTreatmentDTO;
use App\Coatings\Application\UseCase\Command\CreateSurfaceTreatment\CreateSurfaceTreatmentCommand;
use App\Coatings\Application\UseCase\Command\UpdateSurfaceTreatment\UpdateSurfaceTreatmentCommand;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use Symfony\Component\Validator\Constraints as Assert;

class SurfaceTreatmentMapper
{
    /**
     * Собирает команду из плоских данных формы.
     *
     * Если $id === null → CreateSurfaceTreatmentCommand.
     * Если $id передан → UpdateSurfaceTreatmentCommand.
     *
     * @param array<string, mixed> $input
     */
    public function buildCommandFromInputData(
        array $input,
        ?string $id = null,
    ): CreateSurfaceTreatmentCommand|UpdateSurfaceTreatmentCommand {
        $description = (string) ($input['description'] ?? '');
        $code = isset($input['code']) && '' !== trim((string) $input['code'])
            ? (string) $input['code']
            : null;
        $standardCode = isset($input['standardCode']) && '' !== trim((string) $input['standardCode'])
            ? (string) $input['standardCode']
            : null;

        $substrateScope = [];
        foreach ($input['substrateScope'] ?? [] as $substrateValue) {
            $substrateScope[] = Substrate::from((string) $substrateValue);
        }

        if (null === $id) {
            return new CreateSurfaceTreatmentCommand(
                description: $description,
                code: $code,
                standardCode: $standardCode,
                substrateScope: $substrateScope,
            );
        }

        return new UpdateSurfaceTreatmentCommand(
            id: $id,
            description: $description,
            code: $code,
            standardCode: $standardCode,
            substrateScope: $substrateScope,
        );
    }

    /**
     * Раскладывает DTO в плоский набор для формы. NULL → пустая структура.
     *
     * @return array<string, mixed>
     */
    public function buildInputDataFromDto(?SurfaceTreatmentDTO $dto): array
    {
        if (null === $dto) {
            return [
                'description' => '',
                'code' => '',
                'standardCode' => '',
                'substrateScope' => [],
            ];
        }

        return [
            'description' => $dto->description,
            'code' => $dto->code ?? '',
            'standardCode' => $dto->standardCode ?? '',
            'substrateScope' => $dto->substrateScope,
        ];
    }

    /**
     * Структурные Validator constraints для формы подготовки поверхности.
     * Только типы, длины — не бизнес-правила.
     */
    public function getValidationCollection(): Assert\Collection
    {
        return new Assert\Collection([
            'description' => [
                new Assert\NotBlank(),
                new Assert\Length([
                    'max' => 2000,
                    'maxMessage' => 'Описание не должно быть длиннее {{ limit }} символов.',
                ]),
            ],
            'code' => new Assert\Optional([
                new Assert\Length([
                    'max' => 30,
                    'maxMessage' => 'Код не должен быть длиннее {{ limit }} символов.',
                ]),
            ]),
            'standardCode' => new Assert\Optional([
                new Assert\Length([
                    'max' => 100,
                    'maxMessage' => 'Код стандарта не должен быть длиннее {{ limit }} символов.',
                ]),
            ]),
            'substrateScope' => [
                new Assert\Count([
                    'min' => 1,
                    'minMessage' => 'Область применения должна содержать хотя бы один элемент.',
                ]),
                new Assert\All([
                    new Assert\Choice([
                        'choices' => array_map(fn (Substrate $s) => $s->value, Substrate::cases()),
                        'message' => 'Недопустимое значение подложки.',
                    ]),
                ]),
            ],
        ], allowExtraFields: true);
    }
}
