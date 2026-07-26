<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class SurfacePreparation
{
    public function __construct(
        public string $grade,
        public string $description,
        public ?string $standard = null,
    ) {
        $trimmedGrade = trim($this->grade);
        if ('' === $trimmedGrade) {
            throw new AppException('Обозначение подготовки поверхности не может быть пустым.');
        }
        if (mb_strlen($this->grade) > 30) {
            throw new AppException('Обозначение подготовки поверхности не должно превышать 30 символов.');
        }
        if (mb_strlen($this->description) > 500) {
            throw new AppException('Описание подготовки поверхности не должно превышать 500 символов.');
        }
        if (null !== $this->standard) {
            if ('' === trim($this->standard)) {
                throw new AppException('Обозначение стандарта не может быть пустой строкой (используйте null).');
            }
            if (mb_strlen($this->standard) > 50) {
                throw new AppException('Обозначение стандарта не должно превышать 50 символов.');
            }
        }
    }

    /** @return array{grade: string, description: string, standard: ?string} */
    public function toArray(): array
    {
        return [
            'grade' => $this->grade,
            'description' => $this->description,
            'standard' => $this->standard,
        ];
    }

    /** @param array{grade: string, description: string, standard: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self($data['grade'], $data['description'], $data['standard'] ?? null);
    }
}
