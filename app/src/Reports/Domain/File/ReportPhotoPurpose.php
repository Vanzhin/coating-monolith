<?php

declare(strict_types=1);

namespace App\Reports\Domain\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;

/**
 * Назначение файла для фото полевого отчёта: куда класть, под каким ключом, какие ограничения.
 * Строгая проверка (mime/размер/габариты) применяется на promote() — при привязке фото к отчёту.
 */
enum ReportPhotoPurpose: string implements FilePurpose
{
    case Photo = 'photo';

    public function storagePrefix(): string
    {
        return 'reports/'.$this->value; // reports/photo
    }

    public function key(): string
    {
        return 'report.'.$this->value; // report.photo
    }

    public function constraints(): FileConstraints
    {
        return new FileConstraints(10 * 1024 * 1024, ['image/jpeg', 'image/png'], 4000, 4000);
    }
}
