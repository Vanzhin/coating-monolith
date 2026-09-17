<?php
declare(strict_types=1);
namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\TrackedClass;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class TrackedClassTest extends TestCase
{
    public function testHoldsFieldsMap(): void
    {
        $t = new TrackedClass('id', 'App\\X', ['title' => 'Название']);
        self::assertSame(['title' => 'Название'], $t->fields());
    }

    public function testRejectsEmptyFields(): void
    {
        $this->expectException(AppException::class);
        new TrackedClass('id', 'App\\X', []);
    }
}
