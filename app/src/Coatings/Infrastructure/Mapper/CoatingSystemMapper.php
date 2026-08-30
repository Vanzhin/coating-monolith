<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Mapper;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemLayerDTO;
use App\Coatings\Application\DTO\Tags\TagDTO;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\UpdateCoatingSystemMetadata\UpdateCoatingSystemMetadataCommand;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
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
        $environment = EnvironmentType::from($input['environment']);
        $surfaceTreatmentId = (string) ($input['surfaceTreatmentId'] ?? '');
        $tagIds = new StringCollection(...array_values(array_map(
            static fn ($id) => (string) $id,
            (array) ($input['tagIds'] ?? []),
        )));

        if (null === $systemId) {
            return new CreateCoatingSystemCommand(
                title: (string) ($input['title'] ?? ''),
                description: (string) ($input['description'] ?? ''),
                substrate: $substrate,
                environment: $environment,
                surfaceTreatmentId: $surfaceTreatmentId,
                initialLayers: $this->layersFromInput((array) ($input['layers'] ?? [])),
                tagIds: $tagIds,
            );
        }

        return new UpdateCoatingSystemMetadataCommand(
            id: $systemId,
            title: (string) ($input['title'] ?? ''),
            description: (string) ($input['description'] ?? ''),
            substrate: $substrate,
            environment: $environment,
            surfaceTreatmentId: $surfaceTreatmentId,
            tagIds: $tagIds,
        );
    }

    /**
     * Плоский список слоёв формы → нормализованный shape. Только форма данных:
     * кривые строки отбрасываются, dft приводится к int. Бизнес-правила
     * (валидность покрытия, положительность толщины) проверяет домен.
     *
     * @param array<mixed> $raw
     *
     * @return list<array{coatingId: string, dft: int, colorId: string}>
     */
    public function layersFromInput(array $raw): array
    {
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $coatingId = (string) ($item['coatingId'] ?? '');
            if ('' === $coatingId) {
                continue;
            }
            $out[] = [
                'coatingId' => $coatingId,
                'dft' => (int) ($item['dft'] ?? 0),
                'colorId' => (string) ($item['colorId'] ?? ''),
            ];
        }

        return $out;
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
                'environment' => EnvironmentType::Atmospheric->value,
                'surfaceTreatmentId' => '',
                'surfaceTreatmentTitle' => '',
                'layers' => [],
                'tagIds' => [],
            ];
        }

        return [
            'title' => $dto->title,
            'description' => $dto->description,
            'substrate' => $dto->substrate,
            'environment' => $dto->environment,
            'surfaceTreatmentId' => $dto->surfaceTreatmentId,
            'surfaceTreatmentTitle' => $dto->surfaceTreatmentTitle,
            'layers' => array_map(
                fn (CoatingSystemLayerDTO $layer) => [
                    'coatingId' => $layer->coatingId,
                    'dft' => $layer->dft,
                    'colorId' => $layer->colorId,
                    'colorName' => $layer->colorName,
                    'colorRal' => $layer->colorRal,
                    'colorHex' => $layer->colorHex,
                    'colorLabel' => $layer->colorLabel,
                ],
                $dto->layers,
            ),
            'tagIds' => array_map(fn (TagDTO $tag) => $tag->id, $dto->tags),
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
                    'message' => 'Недопустимое значение подложки.',
                ]),
            ],
            'environment' => [
                new Assert\NotBlank(),
                new Assert\Choice([
                    'choices' => array_map(fn (EnvironmentType $e) => $e->value, EnvironmentType::cases()),
                    'message' => 'Недопустимое значение среды эксплуатации.',
                ]),
            ],
            'surfaceTreatmentId' => [
                new Assert\NotBlank(),
                new Assert\Uuid(),
            ],
            'layers' => new Assert\Optional([
                new Assert\All([
                    new Assert\Collection([
                        'coatingId' => [new Assert\NotBlank(), new Assert\Uuid()],
                        'dft' => [
                            new Assert\NotBlank(),
                            new Assert\Positive(),
                            new Assert\GreaterThan(0),
                        ],
                        // Цвет слоя обязателен на запись; членство/колеруемость — в домене.
                        'colorId' => [new Assert\NotBlank(), new Assert\Uuid()],
                    ]),
                ]),
            ]),
            'tagIds' => new Assert\Optional([
                new Assert\All([new Assert\Uuid()]),
            ]),
        ], allowExtraFields: true);
    }
}
