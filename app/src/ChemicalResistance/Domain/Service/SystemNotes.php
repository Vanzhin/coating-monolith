<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Service;

final class SystemNotes
{
    /** @return list<SystemNote> */
    public static function all(): array
    {
        return [
            new SystemNote(
                'Высоковязкие и твёрдые вещества',
                'При этом высоковязкие и твёрдые вещества могут храниться в постоянном контакте с ЛКП с температурой до +70°C, если нет отдельных примечаний.',
            ),
        ];
    }
}
