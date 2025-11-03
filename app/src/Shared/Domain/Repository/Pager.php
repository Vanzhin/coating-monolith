<?php

declare(strict_types=1);

namespace App\Shared\Domain\Repository;

readonly class Pager
{
    public const DEFAULT_LIMIT = 10;
    public const DEFAULT_PAGE = 1;

    public ?int $total_pages;

    public function __construct(
        public int $page,
        public int $perPage,
        public ?int $total_items = null
    ) {
        $this->setTotalPages();
    }

    public static function emptySet(): self
    {
        return new self(1, 0);
    }

    public static function fromPage(?int $page = null, ?int $perPage = null): self
    {
        return new self($page ?? self::DEFAULT_PAGE, $perPage ?? self::DEFAULT_LIMIT);
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
