<?php

declare(strict_types=1);

namespace App\Reports\Application\Service\Mapping;

/**
 * Одна запись маппинга «источник → переменная шаблона». Данные (не код): сейчас их отдаёт
 * хардкод-провайдер на тип, позже — конструктор отчётов из БД/конфига. Слои: variable = префикс,
 * маппер раскрывает в {variable}_layer{N}_{sub}.
 */
final readonly class TemplateMapEntry
{
    private function __construct(
        public TemplateSource $source,
        public string $variable,
        public ?string $headerAttr,
        public ?string $blockKey,
        public ?string $fieldKey,
    ) {
    }

    public static function header(string $attr, string $variable): self
    {
        return new self(TemplateSource::Header, $variable, $attr, null, null);
    }

    public static function field(string $blockKey, string $fieldKey, string $variable): self
    {
        return new self(TemplateSource::Field, $variable, null, $blockKey, $fieldKey);
    }
}
