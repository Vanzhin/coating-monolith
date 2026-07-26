<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemLayerDTO;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata\UpdateCoatingSystemMetadataCommand;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\CoatingSystem\SurfacePreparation;
use Symfony\Component\Validator\Constraints as Assert;

class CoatingSystemMapper
{
    /**
     * Собирает команду из плоских данных формы.
     *
     * Если $systemId === null → CreateCoatingSystemCommand (включает layers).
     * Если $systemId передан → UpdateCoatingSystemMetadataCommand (без layers).
     *
     * @param array<string, mixed> $input
     */
    public function buildCommandFromInputData(
        array $input,
        ?string $systemId = null,
    ): CreateCoatingSystemCommand|UpdateCoatingSystemMetadataCommand {
        $substrate = Substrate::from($input['substrate']);
        $surfacePreparation = $this->buildSurfacePreparation($input['surfacePreparation'] ?? []);

        if (null === $systemId) {
            $layers = [];
            foreach ($input['layers'] ?? [] as $layer) {
                $layers[] = [
                    'coatingId' => (string) $layer['coatingId'],
                    'dft' => (int) $layer['dft'],
                ];
            }

            return new CreateCoatingSystemCommand(
                title: (string) ($input['title'] ?? ''),
                description: (string) ($input['description'] ?? ''),
                substrate: $substrate,
                surfacePreparation: $surfacePreparation,
                initialLayers: $layers,
            );
        }

        return new UpdateCoatingSystemMetadataCommand(
            id: $systemId,
            title: (string) ($input['title'] ?? ''),
            description: (string) ($input['description'] ?? ''),
            substrate: $substrate,
            surfacePreparation: $surfacePreparation,
        );
    }

    /**
     * Раскладывает DTO в плоский набор для формы. NULL → пустая структура.
     *
     * @return array<string, mixed>
     */
    public function buildInputDataFromDto(?CoatingSystemDTO $dto): array
    {
        if (null === $dto) {
            return [
                'title' => '',
                'description' => '',
                'substrate' => '',
                'surfacePreparation' => [
                    'grade' => '',
                    'description' => '',
                    'standard' => null,
                ],
                'layers' => [],
            ];
        }

        return [
            'title' => $dto->title,
            'description' => $dto->description,
            'substrate' => $dto->substrate,
            'surfacePreparation' => [
                'grade' => $dto->surfacePreparationGrade,
                'description' => $dto->surfacePreparationDescription,
                'standard' => $dto->surfacePreparationStandard,
            ],
            'layers' => array_map(
                fn (CoatingSystemLayerDTO $layer) => [
                    'coatingId' => $layer->coatingId,
                    'dft' => $layer->dft,
                ],
                $dto->layers,
            ),
        ];
    }

    /**
     * Структурные Validator constraints для формы системы покрытий.
     * Только типы, длины — не бизнес-правила.
     */
    public function getValidationCollection(): Assert\Collection
    {
        return new Assert\Collection([
            'title' => [
                new Assert\NotBlank(),
                new Assert\Length([
                    'max' => 100,
                    'maxMessage' => 'Название не должно быть длиннее {{ limit }} символов.',
                ]),
            ],
            'description' => new Assert\Optional([
                new Assert\Length([
                    'max' => 2000,
                    'maxMessage' => 'Описание не должно быть длиннее {{ limit }} символов.',
                ]),
            ]),
            'substrate' => [
                new Assert\NotBlank(),
                new Assert\Choice([
                    'choices' => array_map(fn (Substrate $s) => $s->value, Substrate::cases()),
                    'message' => 'Недопустимое значение субстрата.',
                ]),
            ],
            'surfacePreparation' => new Assert\Collection([
                'grade' => [
                    new Assert\NotBlank(),
                    new Assert\Length([
                        'max' => 30,
                        'maxMessage' => 'Обозначение подготовки не должно быть длиннее {{ limit }} символов.',
                    ]),
                ],
                'description' => new Assert\Optional([
                    new Assert\Length([
                        'max' => 500,
                        'maxMessage' => 'Описание подготовки не должно быть длиннее {{ limit }} символов.',
                    ]),
                ]),
                'standard' => new Assert\Optional([
                    new Assert\Length([
                        'max' => 50,
                        'maxMessage' => 'Обозначение стандарта не должно быть длиннее {{ limit }} символов.',
                    ]),
                ]),
            ]),
            'layers' => new Assert\Optional([
                new Assert\All([
                    new Assert\Collection([
                        'coatingId' => [new Assert\NotBlank(), new Assert\Uuid()],
                        'dft' => [
                            new Assert\NotBlank(),
                            new Assert\Positive(),
                            new Assert\GreaterThan(0),
                        ],
                    ]),
                ]),
            ]),
        ], allowExtraFields: true);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function buildSurfacePreparation(array $raw): SurfacePreparation
    {
        $standard = isset($raw['standard']) && '' !== (string) $raw['standard']
            ? (string) $raw['standard']
            : null;

        return new SurfacePreparation(
            grade: (string) ($raw['grade'] ?? ''),
            description: (string) ($raw['description'] ?? ''),
            standard: $standard,
        );
    }
}
