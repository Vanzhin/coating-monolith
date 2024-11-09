<?php

declare(strict_types=1);

namespace App\Shared\Domain\Repository;

readonly class Pager
{
    public ?int $total_pages;

    public function __construct(
        public int  $page,
        public int  $perPage,
        public ?int $total_items = null
    )
    {
        $this->setTotalPages();
    }

    public static function emptySet(): self
    {
        return new self(1, 0);
    }

    public static function fromPage(int $page, int $perPage): self
    {
        return new self($page, $perPage);
    }

    public function getOffset(): int
    {
        if (1 === $this->page) {
            return 0;
        }

        return $this->page * $this->perPage - $this->perPage;
    }

    public function getLimit(): int
    {
        return $this->perPage;
    }

    private function setTotalPages(): void
    {
        if (!$this->total_items) {
            $this->total_pages = null;
        } else {
            $this->total_pages = (int)ceil($this->total_items / $this->perPage);
        }
    }
}
