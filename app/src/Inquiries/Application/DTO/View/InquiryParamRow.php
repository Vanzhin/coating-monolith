<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Строка распознанного параметра для карточки: подпись + значение + кто установил (робот/человек).
 * byHuman — инженер поправил эту ось вручную (origin Manual хотя бы у одного исходного ключа строки);
 * иначе значение дала модель/кэш.
 */
final readonly class InquiryParamRow
{
    public function __construct(
        public string $label,
        public string $value,
        public bool $byHuman,
    ) {
    }
}
