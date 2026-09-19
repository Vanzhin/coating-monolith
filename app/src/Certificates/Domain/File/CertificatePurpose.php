<?php

declare(strict_types=1);

namespace App\Certificates\Domain\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;

enum CertificatePurpose: string implements FilePurpose
{
    case Scan = 'scan';

    public function storagePrefix(): string
    {
        return 'certificates/'.$this->value;
    }

    public function key(): string
    {
        return 'certificate.'.$this->value;
    }

    public function constraints(): FileConstraints
    {
        return new FileConstraints(20 * 1024 * 1024, ['application/pdf']);
    }
}
