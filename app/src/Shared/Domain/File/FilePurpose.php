<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

interface FilePurpose
{
    /** Префикс каталога в хранилище, напр. 'certificates/scan'. */
    public function storagePrefix(): string;

    /** Стабильный ключ назначения для реестра, напр. 'certificate.scan'. */
    public function key(): string;

    public function constraints(): FileConstraints;
}
