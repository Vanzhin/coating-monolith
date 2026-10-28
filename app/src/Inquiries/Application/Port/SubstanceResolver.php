<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Port;

/**
 * Резолв имени вещества (как в тексте запроса) в id реестра химстойкости. Порт Inquiries:
 * реализация обращается к ChemicalResistance через его Application-запрос, Inquiries не знает
 * домена веществ. Не нашлось → null (процессор положит имя в «не распознанные»).
 */
interface SubstanceResolver
{
    public function resolve(string $name): ?string;
}
