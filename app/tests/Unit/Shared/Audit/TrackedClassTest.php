<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\TrackedClass;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class TrackedClassTest extends TestCase
{
    public function test_holds_fields_map(): void
    {
        $t = new TrackedClass('id', 'App\\X', ['title' => 'Название']);
        self::assertSame(['title' => 'Название'], $t->fields());
    }

    public function test_rejects_empty_fields(): void
    {
        $this->expectException(AppException::class);
        new TrackedClass('id', 'App\\X', []);
    }
}
