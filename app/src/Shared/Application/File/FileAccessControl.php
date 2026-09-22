<?php

declare(strict_types=1);

namespace App\Shared\Application\File;

use App\Shared\Domain\File\StoredFile;

/**
 * Контроль доступа к сохранённому файлу — по НАЗНАЧЕНИЮ (StoredFile::purpose()). Общая отдача файла
 * (ServeAction) делегирует сюда: каждый контекст объявляет свой контроль для своих назначений и решает,
 * кто может смотреть (владелец/админ и т.п.). Назначение без контроля не отдаётся (deny by default).
 */
interface FileAccessControl
{
    /** Отвечает ли контроль за данное назначение файла (ключ FilePurpose). */
    public function supports(?string $purposeKey): bool;

    /** Может ли текущий актор просматривать файл. */
    public function canView(StoredFile $file): bool;
}
