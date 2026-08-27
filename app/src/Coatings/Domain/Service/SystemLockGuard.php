<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Заморозка сертифицированной системы: если к системе привязан документ (сертификат/заключение),
 * систему менять нельзя. Правило кросс-контекстное (документ живёт в Certificates), поэтому не в
 * агрегате CoatingSystem, а в доменном сервисе, читающем факт через порт. Зовётся из командных
 * хендлеров мутаций системы (слои, метаданные, удаление).
 */
final readonly class SystemLockGuard
{
    public function __construct(private SystemCertificatesGateway $gateway)
    {
    }

    public function assertModifiable(string $systemId): void
    {
        if ($this->gateway->hasCertificates($systemId)) {
            throw new AppException('К системе привязан сертификат — систему менять нельзя. Создайте новую систему (при необходимости дублированием) и правьте её сколько угодно.');
        }
    }
}
