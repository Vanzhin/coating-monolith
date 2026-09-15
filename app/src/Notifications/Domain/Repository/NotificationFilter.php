<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

final class NotificationFilter
{
    public function __construct(
        public string $ownerUlid,
        public ?bool $isRead = null,
        private ?Pager $pager = null,
    ) {
        if (!$this->pager) {
            $this->pager = Pager::fromPage();
        }
    }

    public function getPager(): Pager
    {
        return $this->pager;
    }
}
