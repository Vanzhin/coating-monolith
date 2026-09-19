<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\SubmitForReview;

use App\Shared\Application\Command\Command;

final readonly class SubmitForReviewCommand extends Command
{
    public function __construct(public string $reportId)
    {
    }
}
