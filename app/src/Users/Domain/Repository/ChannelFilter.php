<?php

declare(strict_types=1);

namespace App\Users\Domain\Repository;

use App\Shared\Domain\Repository\Pager;

final class ChannelFilter
{
    //todo расширить фильтр

    public function __construct(
        public ?string $type = null,
        public ?string $value = null,
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
