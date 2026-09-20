<?php

declare(strict_types=1);

namespace App\Reports\Domain\Service;

use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;
use App\Shared\Domain\Aggregate\ValueObject\CoatingThickness;
use App\Shared\Domain\Aggregate\ValueObject\Percent;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Aggregate\ValueObject\Thinner;
use App\Shared\Domain\ValueObject\DateTimeInterval;
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

    /** Разумный предел числа фото на отчёт. */
    private const int MAX_PHOTOS = 20;

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
                if (FieldType::PhotoSlot === $field->type) {
                    $this->validatePhotos($definition->title(), $field, $value, $strict);

                    continue;
                }
                if (FieldType::StringList === $field->type) {
                    $this->validateStringList($definition->title(), $field, $value, $strict);

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

    /**
     * Фото — тот же список строк ({file: uuid, caption?}), но с пределом числа и без обязательности
     * (опциональный блок). file (uuid хранёного файла) обязателен в каждой строке.
     */
    private function validatePhotos(string $blockTitle, Field $field, mixed $value, bool $strict): void
    {
        if (is_array($value) && array_is_list($value) && count($value) > self::MAX_PHOTOS) {
            throw new AppException(sprintf('Фотографий в блоке «%s» не может быть больше %d.', $blockTitle, self::MAX_PHOTOS));
        }
        $this->validateList($blockTitle, $field, $value, $strict);
    }

    /**
     * Список строк (рекомендации/выводы): плоский массив строк. Пустые пункты допустимы (отбросятся
     * при проекции). Обязательное поле в strict → нужен хотя бы один непустой пункт.
     */
    private function validateStringList(string $blockTitle, Field $field, mixed $value, bool $strict): void
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

        $nonEmpty = 0;
        foreach ($value as $item) {
            if (!is_string($item)) {
                throw new AppException(sprintf('Поле «%s / %s» должно быть списком строк.', $blockTitle, $field->label));
            }
            if ('' !== trim($item)) {
                ++$nonEmpty;
            }
        }
        if ($strict && $field->required && 0 === $nonEmpty) {
            throw new AppException(sprintf('Не заполнено обязательное поле: %s / %s.', $blockTitle, $field->label));
        }
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

    /**
     * Толщина сухого слоя — объект {min,max,mean}. Пусто (все поля не заполнены) — допустимо. Иначе
     * все три обязаны быть числами; инвариант «>0 и min≤max» стережёт VO CoatingThickness.
     */
    private function checkThickness(string $blockTitle, Field $field, mixed $value): void
    {
        if (!is_array($value)) {
            throw new AppException(sprintf('Поле «%s / %s» должно быть объектом толщины (мин/макс/средняя).', $blockTitle, $field->label));
        }
        $parts = ['min' => $value['min'] ?? '', 'max' => $value['max'] ?? '', 'mean' => $value['mean'] ?? ''];
        if ('' === implode('', array_map(static fn ($v): string => (string) $v, $parts))) {
            return; // не заполнено — опционально
        }
        foreach ($parts as $subValue) {
            if (!is_numeric($subValue)) {
                throw new AppException(sprintf('Поле «%s / %s»: мин/макс/средняя должны быть числами.', $blockTitle, $field->label));
            }
        }
        new CoatingThickness(
            new PositiveNumberRange((float) $parts['min'], (float) $parts['max']),
            new PositiveNumber((float) $parts['mean']),
        );
    }

    /**
     * Диапазон толщины без среднего — объект {min,max}. Пусто (обе границы не заполнены) — допустимо.
     * Иначе обе обязаны быть числами; инвариант «>0 и min≤max» стережёт VO PositiveNumberRange.
     */
    private function checkNumberRange(string $blockTitle, Field $field, mixed $value): void
    {
        if (!is_array($value)) {
            throw new AppException(sprintf('Поле «%s / %s» должно быть диапазоном (мин/макс).', $blockTitle, $field->label));
        }
        $min = (string) ($value['min'] ?? '');
        $max = (string) ($value['max'] ?? '');
        if ('' === $min && '' === $max) {
            return; // не заполнено — опционально
        }
        if (!is_numeric($min) || !is_numeric($max)) {
            throw new AppException(sprintf('Поле «%s / %s»: мин/макс должны быть числами.', $blockTitle, $field->label));
        }
        new PositiveNumberRange((float) $min, (float) $max);
    }

    /**
     * Разбавитель — объект {name, batch, percent}. Пусто — допустимо. Иначе процент (если задан) —
     * число; инвариант [0;100] стережёт Percent внутри VO Thinner.
     */
    private function checkThinner(string $blockTitle, Field $field, mixed $value): void
    {
        if (!is_array($value)) {
            throw new AppException(sprintf('Поле «%s / %s» должно быть объектом разбавителя.', $blockTitle, $field->label));
        }
        $name = trim((string) ($value['name'] ?? ''));
        $batch = trim((string) ($value['batch'] ?? ''));
        $percent = (string) ($value['percent'] ?? '');
        if ('' === $name && '' === $batch && '' === $percent) {
            return; // не заполнено — опционально
        }
        if ('' !== $percent && !is_numeric($percent)) {
            throw new AppException(sprintf('Поле «%s / %s»: количество (%%) должно быть числом.', $blockTitle, $field->label));
        }
        new Thinner($name, $batch, new Percent((float) $percent));
    }

    /**
     * Период нанесения — объект {from,to} (дата+время). Пусто — допустимо. Иначе — валидные
     * дата/время; инвариант «from ≤ to» и «хотя бы одна граница» стережёт VO DateTimeInterval.
     */
    private function checkDateTimeRange(string $blockTitle, Field $field, mixed $value): void
    {
        if (!is_array($value)) {
            throw new AppException(sprintf('Поле «%s / %s» должно быть периодом (с/по).', $blockTitle, $field->label));
        }
        $from = trim((string) ($value['from'] ?? ''));
        $to = trim((string) ($value['to'] ?? ''));
        if ('' === $from && '' === $to) {
            return; // не заполнено — опционально
        }
        $parse = function (string $raw, string $part) use ($blockTitle, $field): ?\DateTimeImmutable {
            if ('' === $raw) {
                return null;
            }
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                throw new AppException(sprintf('Поле «%s / %s»: «%s» — неверная дата/время.', $blockTitle, $field->label, $part));
            }
        };
        new DateTimeInterval($parse($from, 'с'), $parse($to, 'по'));
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
                if ($field->positive) {
                    new PositiveNumber((float) $value); // инвариант «> 0» — в VO, не дублируем
                }
                if ($field->percent) {
                    new Percent((float) $value); // инвариант «[0;100]» — в VO
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
            case FieldType::CoatingRef:
            case FieldType::ColorRef:
                // Снимок ссылки на каталог: только shape (id + название непусты), без проверки
                // существования — снимок самодостаточен и офлайн-безопасен (Вариант A).
                if (!is_array($value)
                    || !isset($value['id'], $value['title'])
                    || !is_string($value['id']) || '' === $value['id']
                    || !is_string($value['title']) || '' === $value['title']) {
                    throw new AppException(sprintf('Поле «%s / %s» должно быть ссылкой на каталог (id и название).', $blockTitle, $field->label));
                }
                break;
            case FieldType::Thickness:
                $this->checkThickness($blockTitle, $field, $value);
                break;
            case FieldType::NumberRange:
                $this->checkNumberRange($blockTitle, $field, $value);
                break;
            case FieldType::Thinner:
                $this->checkThinner($blockTitle, $field, $value);
                break;
            case FieldType::DateTimeRange:
                $this->checkDateTimeRange($blockTitle, $field, $value);
                break;
            default:
                // Медиа (PhotoSlot) — позже.
                break;
        }
    }
}
