<?php

declare(strict_types=1);

namespace App\Reports\Application\Service\AccessControl;

use App\Reports\Domain\File\ReportPhotoPurpose;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\File\FileAccessControl;
use App\Shared\Domain\File\StoredFile;

/**
 * Доступ к фото отчёта — по правилам самого отчёта: владелец или админ. ownerId файла = id отчёта
 * (проставляется при promote). Файл без найденного отчёта — не отдаём.
 */
final readonly class ReportPhotoFileAccessControl implements FileAccessControl
{
    public function __construct(
        private ReportRepositoryInterface $reports,
        private ReportAccessControl $access,
    ) {
    }

    public function supports(?string $purposeKey): bool
    {
        return ReportPhotoPurpose::Photo->key() === $purposeKey;
    }

    public function canView(StoredFile $file): bool
    {
        $ownerId = $file->ownerId();
        if (null === $ownerId) {
            return false;
        }
        $report = $this->reports->findOneById($ownerId);

        return null !== $report && $this->access->canEdit($report);
    }
}
