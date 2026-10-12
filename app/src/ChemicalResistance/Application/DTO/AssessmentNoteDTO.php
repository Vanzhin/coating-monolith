<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\DTO;

/**
 * Примечание в read-view оценки химстойкости: заголовок, текст и признак системного
 * (глобального) примечания. Единая форма для таблицы покрытия и карточек обратного поиска.
 */
final readonly class AssessmentNoteDTO
{
    public function __construct(
        public string $title,
        public string $description,
        public bool $isSystem,
    ) {
    }
}
