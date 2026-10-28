<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\ReprocessInquiry;

use App\Shared\Application\Command\Command;

/**
 * Инженер поправил распознанные параметры руками и переподбирает — СИНХРОННО (модель не зовём,
 * параметры уже готовы). parameters — плоский снимок CoatingSystemsFilterInput (та же непрозрачная
 * форма, что хранит запрос), собирает маппер из формы.
 */
final readonly class ReprocessInquiryCommand extends Command
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public string $inquiryId,
        public array $parameters,
    ) {
    }
}
