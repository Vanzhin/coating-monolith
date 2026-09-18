<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

/**
 * Результат сверки конфига с шаблоном. Данные, не исключение — потребитель строит из них
 * человекочитаемые сообщения формы. Блокируют генерацию: missing, unresolvedImages, invalidBlocks.
 */
final readonly class ValidationResult
{
    /**
     * @param list<string> $missing          обязательные плейсхолдеры без значения
     * @param list<string> $unresolvedImages обязательные картинки с ненайденным/нечитаемым файлом
     * @param list<string> $invalidBlocks    опциональные блоки с частично заполненными полями
     * @param list<string> $skipped          опциональные плейсхолдеры, пропущенные (не блокирует)
     * @param list<string> $unused           ключи данных, которых нет в шаблоне (не блокирует)
     */
    public function __construct(
        public array $missing = [],
        public array $unresolvedImages = [],
        public array $invalidBlocks = [],
        public array $skipped = [],
        public array $unused = [],
    ) {
    }

    public function isValid(): bool
    {
        return [] === $this->missing
            && [] === $this->unresolvedImages
            && [] === $this->invalidBlocks;
    }
}
