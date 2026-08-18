<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Color;

use App\Coatings\Domain\Aggregate\Color\HexColor;
use App\Coatings\Domain\Aggregate\Color\RalColor;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RalColorTest extends TestCase
{
    public function test_holds_code_name_and_hex(): void
    {
        $ral = new RalColor('RAL 7040', 'Оконно-серый', new HexColor('#9DA3A6'));

        self::assertSame('RAL 7040', $ral->code);
        self::assertSame('Оконно-серый', $ral->name);
        self::assertSame('#9DA3A6', $ral->hex->value);
    }

    public function test_rejects_malformed_code(): void
    {
        $this->expectException(AppException::class);
        new RalColor('7040', 'Оконно-серый', new HexColor('#9DA3A6'));
    }

    public function test_rejects_empty_name(): void
    {
        $this->expectException(AppException::class);
        new RalColor('RAL 7040', '', new HexColor('#9DA3A6'));
    }
}
