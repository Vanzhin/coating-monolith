<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Лёгкий снимок статуса запроса для поллинга карточки: значение статуса + «ещё в работе».
 * Без параметров/вложений — опрос частый, тащить тяжёлое незачем.
 */
final readonly class InquiryStatusView
{
    public function __construct(
        public string $status,
        public bool $processing,
    ) {
    }
}
