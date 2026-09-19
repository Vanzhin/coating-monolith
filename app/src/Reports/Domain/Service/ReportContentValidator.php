<?php

declare(strict_types=1);

namespace App\Reports\Domain\Service;

use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Валидирует content отчёта против схемы блоков его типа.
 * - strict=false (черновик/save): неполнота допустима, проверяем только типы заполненных значений.
 * - strict=true  (submitForReview): все обязательные поля должны быть заполнены.
 * Скаляры и композиты (Layers/List) проверяются; ссылки/медиа (*Ref/PhotoSlot) — позже.
 */
final readonly class ReportContentValidator
{
    /** Движок рендера без повтора → шаблоны только на 1-4 слоя. */
    private const int MAX_LAYERS = 4;

    public function __construct(private BlockRegistry $registry)
    {
    }

    /**
     * @param array<string, mixed> $content
     */
    public function validate(ReportType $type, array $content, bool $strict): void
    {
        foreach ($type->blockKeys() as $blockKey) {
            $definition = $this->registry->get($blockKey);
            $blockData = $content[$blockKey->value] ?? [];
            if (!is_array($blockData)) {
                throw new AppException(sprintf('Блок «%s» имеет неверный формат данных.', $definition->title()));
            }

            foreach ($definition->fields() as $field) {
                $value = $blockData[$field->key] ?? null;
                if (FieldType::Layers === $field->type) {
                    $this->validateLayers($definition->title(), $field, $value, $strict);

                    continue;
                }
                if (FieldType::ListRows === $field->type) {
                    $this->validateList($definition->title(), $field, $value, $strict);

                    continue;
                }
                if (null === $value || '' === $value) {
                    if ($strict && $field->required) {
                        throw new AppException(sprintf('Не заполнено обязательное поле: %s / %s.', $definition->title(), $field->label));
                    }

                    continue;
                }
                $this->checkType($definition->title(), $field, $value);
            }
        }
    }

    /**
     * Слои — тот же список строк, но ограничены числом шаблонных колонок (≤ MAX_LAYERS).
     * Проекция раскладывает их в индексированные ключи application_layerN_*.
     */
    private function validateLayers(string $blockTitle, Field $field, mixed $value, bool $strict): void
    {
        if (is_array($value) && array_is_list($value) && count($value) > self::MAX_LAYERS) {
            throw new AppException(sprintf('Слоёв в блоке «%s» не может быть больше %d.', $blockTitle, self::MAX_LAYERS));
        }
        $this->validateList($blockTitle, $field, $value, $strict);
    }

    private function validateList(string $blockTitle, Field $field, mixed $value, bool $strict): void
    {
        if (null === $value || [] === $value) {
            if ($strict && $field->required) {
                throw new AppException(sprintf('Не заполнено обязательное поле: %s / %s.', $blockTitle, $field->label));
            }

            return;
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new AppException(sprintf('Поле «%s / %s» должно быть списком строк.', $blockTitle, $field->label));
        }

        $rowScope = $blockTitle.' / '.$field->label;
        foreach ($value as $row) {
            if (!is_array($row)) {
                throw new AppException(sprintf('Строка списка «%s» имеет неверный формат.', $rowScope));
            }
            foreach ($field->itemFields as $sub) {
                $subValue = $row[$sub->key] ?? null;
                if (null === $subValue || '' === $subValue) {
                    if ($strict && $sub->required) {
                        throw new AppException(sprintf('Не заполнено обязательное поле: %s / %s.', $rowScope, $sub->label));
                    }

                    continue;
                }
                $this->checkType($rowScope, $sub, $subValue);
            }
        }
    }

    private function checkType(string $blockTitle, Field $field, mixed $value): void
    {
        switch ($field->type) {
            case FieldType::Enum:
                if (!in_array((string) $value, $field->options, true)) {
                    throw new AppException(sprintf('Недопустимое значение поля «%s / %s»: «%s».', $blockTitle, $field->label, (string) $value));
                }
                break;
            case FieldType::Number:
                if (!is_numeric($value)) {
                    throw new AppException(sprintf('Поле «%s / %s» должно быть числом.', $blockTitle, $field->label));
                }
                break;
            case FieldType::Bool:
                if (!is_bool($value)) {
                    throw new AppException(sprintf('Поле «%s / %s» должно быть да/нет.', $blockTitle, $field->label));
                }
                break;
            case FieldType::Date:
                if (!is_string($value) || false === \DateTimeImmutable::createFromFormat('!Y-m-d', $value)) {
                    throw new AppException(sprintf('Поле «%s / %s» должно быть датой (ГГГГ-ММ-ДД).', $blockTitle, $field->label));
                }
                break;
            case FieldType::Text:
            case FieldType::TextArea:
            case FieldType::TimeRange:
                if (!is_string($value)) {
                    throw new AppException(sprintf('Поле «%s / %s» должно быть строкой.', $blockTitle, $field->label));
                }
                break;
            default:
                // Композиты/ссылки/медиа (Layers/List/*Ref/PhotoSlot) — в 3a не валидируем.
                break;
        }
    }
}
