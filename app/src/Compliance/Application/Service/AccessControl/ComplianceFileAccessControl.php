<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service\AccessControl;

use App\Compliance\Domain\File\RequirementScanPurpose;
use App\Shared\Application\File\FileAccessControl;
use App\Shared\Domain\File\StoredFile;

/**
 * Доступ к файлам Compliance (скан выдачи, подписанная карточка). Просмотр — любому авторизованному
 * (модель кабинета: смотреть всем, мутировать админам); аутентификация обеспечена security.yaml.
 * Назначения не Compliance сюда не попадают (deny-by-default в общей отдаче).
 */
final class ComplianceFileAccessControl implements FileAccessControl
{
    public function supports(?string $purposeKey): bool
    {
        return \in_array($purposeKey, [
            RequirementScanPurpose::FulfillmentScan->key(),
            RequirementScanPurpose::SignedCard->key(),
            RequirementScanPurpose::WriteOffActScan->key(),
        ], true);
    }

    public function canView(StoredFile $file): bool
    {
        return true;
    }
}
