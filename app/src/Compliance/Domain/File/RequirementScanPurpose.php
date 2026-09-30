<?php

declare(strict_types=1);

namespace App\Compliance\Domain\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;

/**
 * Назначения файлов Compliance: скан факта выдачи (к записи журнала) и подписанный документ-карточка
 * (к RequirementDocument, T6). owner — id `ProfileCompliance`/`RequirementDocument`; доступ — через
 * {@see \App\Compliance\Application\Service\AccessControl\ComplianceFileAccessControl}.
 */
enum RequirementScanPurpose: string implements FilePurpose
{
    case FulfillmentScan = 'fulfillment_scan';
    case SignedCard = 'signed_card';

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
        return new FileConstraints(15 * 1024 * 1024, ['application/pdf', 'image/jpeg', 'image/png']);
    }
}
