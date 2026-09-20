<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateReportHeader;

use App\Shared\Application\Command\Command;
use App\Shared\Domain\ValueObject\DateTimeInterval;

/**
 * Правка реквизитов отчёта: дата, № акта, адрес, период работ, ссылки (проект/заказчик/подрядчик).
 * Тип и система выбраны при создании и здесь НЕ меняются (система засеяла план один раз).
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
        public ?string $address = null,
        public ?DateTimeInterval $workPeriod = null,
    ) {
    }
}
