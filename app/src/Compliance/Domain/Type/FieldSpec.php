<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

/** Описание одного поля схемы журнала инструктажа (config-first: форма и проектор идут по этому списку). */
final readonly class FieldSpec
{
    /** @param list<string> $options варианты для Select (иначе пусто) */
    public function __construct(
        public string $key,
        public string $label,
        public FieldKind $kind,
        public bool $required = false,
        public array $options = [],
        public ?string $group = null,
    ) {
    }
}
