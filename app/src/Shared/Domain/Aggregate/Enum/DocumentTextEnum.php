<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate\Enum;

/**
 * Классификация с коротким `value` (форма/UI/хранение) и полным предложением для документа.
 * Проектор акта печатает `documentText()`; форма показывает короткий `value`. Реализуют строковые
 * enum'ы (BackedEnum) стандартных классификаций подготовки поверхности.
 */
interface DocumentTextEnum
{
    public function documentText(): string;
}
