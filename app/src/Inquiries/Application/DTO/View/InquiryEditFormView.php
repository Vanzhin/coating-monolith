<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterOptionsView;

/**
 * Данные формы правки параметров запроса: текущие (распознанные) значения для предзаполнения +
 * дерево вариантов фильтра Coatings. Вещества предвыбираются по id из params.substanceIds.
 */
final readonly class InquiryEditFormView
{
    public function __construct(
        public string $inquiryId,
        public CoatingSystemsFilterInput $params,
        public CoatingSystemsFilterOptionsView $options,
    ) {
    }
}
