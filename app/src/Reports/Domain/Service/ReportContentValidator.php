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
 * В 3a обрабатываются только скалярные поля; композиты/ссылки/медиа — в 3b.
 */
final readonly class ReportContentValidator
{
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
