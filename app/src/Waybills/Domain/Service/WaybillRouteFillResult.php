<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Service;

use App\Waybills\Domain\Aggregate\Route\Route;

final readonly class WaybillRouteFillResult
{
    private function __construct(
        private ?Route $route,
        private ?string $error,
    ) {
    }

    public static function success(Route $route): self
    {
        return new self($route, null);
    }

    public static function failure(string $error): self
    {
        return new self(null, $error);
    }

    public function isSuccessful(): bool
    {
        return null !== $this->route;
    }

    public function route(): ?Route
    {
        return $this->route;
    }

    public function error(): ?string
    {
        return $this->error;
    }
}
