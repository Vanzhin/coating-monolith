<?php

declare(strict_types=1);

namespace App\Compliance\Domain\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;

/**
 * Назначение файла: Word/Excel-шаблон документа требования (журнал инструктажа, карточка). owner — id
 * требования ({@see \App\Compliance\Domain\Aggregate\Requirement\Requirement}); по шаблону печатается
 * акт/карточка этого требования ({@see \App\Compliance\Infrastructure\Controller\Document\DownloadCardAction}).
 * Грузится прямым {@see \App\Shared\Domain\File\FileStorage::store()} (docx не входит в staged allow-list).
 */
enum RequirementTemplatePurpose: string implements FilePurpose
{
    case Template = 'requirement_template';

    public function storagePrefix(): string
    {
        return 'compliance/'.$this->value;
    }

    public function key(): string
    {
        return 'compliance.'.$this->value;
    }

    public function constraints(): FileConstraints
    {
        return new FileConstraints(15 * 1024 * 1024, [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
