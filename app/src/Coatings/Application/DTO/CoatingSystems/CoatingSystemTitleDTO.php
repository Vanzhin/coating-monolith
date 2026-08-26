<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Лёгкий {id, title} системы — для гидрации чипов/ссылок по id (кросс-контекст).
 * Без полного CoatingSystemDTO с compliance/слоями: гидрации нужно только название.
 */
final readonly class CoatingSystemTitleDTO
{
    public function __construct(
        public string $id,
        public string $title,
    ) {
    }
}
