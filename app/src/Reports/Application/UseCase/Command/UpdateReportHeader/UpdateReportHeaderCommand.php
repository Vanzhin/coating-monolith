<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateReportHeader;

use App\Shared\Application\Command\Command;

/**
 * Правка реквизитов отчёта: дата, № акта, ссылки (проект/заказчик/подрядчик/система). Тип не меняем —
 * он задаёт композицию блоков. При смене системы блок «Система (план)» пере-засевается.
 */
final readonly class UpdateReportHeaderCommand extends Command
{
    public function __construct(
        public string $reportId,
        public ?\DateTimeImmutable $reportDate = null,
        public ?string $actNumber = null,
        public ?string $projectId = null,
        public ?string $customerId = null,
        public ?string $contractorId = null,
        public ?string $systemId = null,
    ) {
    }
}
