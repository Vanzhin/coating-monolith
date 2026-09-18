<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\ImageValue;
use App\Shared\Domain\Templating\RenderData;
use App\Shared\Domain\Templating\TextValue;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class RenderDataTest extends TestCase
{
    public function test_variable_names(): void
    {
        $data = new RenderData(['object' => new TextValue('1'), 'date' => new TextValue('2')]);

        self::assertSame(['object', 'date'], $data->variableNames());
    }

    public function test_get_and_has(): void
    {
        $photo = new ImageValue('/p.jpg');
        $data = new RenderData(['photo' => $photo]);

        self::assertTrue($data->has('photo'));
        self::assertSame($photo, $data->get('photo'));
        self::assertFalse($data->has('nope'));
        self::assertNull($data->get('nope'));
    }

    public function test_empty_data(): void
    {
        self::assertSame([], (new RenderData([]))->variableNames());
    }

    public function test_rejects_non_template_value(): void
    {
        $this->expectException(AppException::class);

        /* @phpstan-ignore-next-line intentional bad input */
        new RenderData(['object' => 'raw string']);
    }
}
