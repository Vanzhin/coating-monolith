<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Application\DTO;

/**
 * Лёгкая ссылка на вещество (id + каноническое имя) — для чипов выбранных веществ
 * и разбивки вердикта по веществам на странице «Химстойкость».
 */
final readonly class SubstanceRefDTO
{
    public function __construct(
        public string $id,
        public string $canonicalName,
    ) {
    }
}
