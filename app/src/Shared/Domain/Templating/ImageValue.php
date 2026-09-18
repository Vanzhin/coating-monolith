<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Значение-картинка: путь к файлу + опциональный размер в пикселях.
 * Размер не задан — драйвер клампит ширину до дефолта, сохраняя пропорции.
 * Существование/читаемость файла проверяет драйвер в момент validate/render.
 */
final readonly class ImageValue implements TemplateValue
{
    public function __construct(
        public string $path,
        public ?int $width = null,
        public ?int $height = null,
    ) {
        if ('' === $path) {
            throw new AppException('Путь к картинке не может быть пустым.');
        }
        if (null !== $width && $width <= 0) {
            throw new AppException('Ширина картинки должна быть положительной.');
        }
        if (null !== $height && $height <= 0) {
            throw new AppException('Высота картинки должна быть положительной.');
        }
    }
}
