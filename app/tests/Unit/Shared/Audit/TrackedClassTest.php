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

    public function test_old_string_form_gives_label_and_scalar_kind(): void
    {
        $t = new TrackedClass('id', 'App\\X', ['title' => 'Название']);

        self::assertSame(['title' => 'Название'], $t->fields());
        self::assertSame(['title' => 'scalar'], $t->kinds());
    }

    public function test_new_shape_form_gives_label_and_configured_kind(): void
    {
        $t = new TrackedClass('id', 'App\\X', ['dftRange' => ['label' => 'Толщина плёнки (DFT)', 'kind' => 'dft']]);

        self::assertSame(['dftRange' => 'Толщина плёнки (DFT)'], $t->fields());
        self::assertSame(['dftRange' => 'dft'], $t->kinds());
    }

    public function test_empty_label_in_new_shape_falls_back_to_field_name(): void
    {
        $t = new TrackedClass('id', 'App\\X', ['title' => ['label' => '', 'kind' => 'scalar']]);

        self::assertSame(['title' => 'title'], $t->fields());
    }

    public function test_missing_kind_in_new_shape_falls_back_to_scalar(): void
    {
        $t = new TrackedClass('id', 'App\\X', ['title' => ['label' => 'Название']]);

        self::assertSame(['title' => 'scalar'], $t->kinds());
    }

    public function test_mixed_old_and_new_forms_in_the_same_map_are_read_tolerantly(): void
    {
        $t = new TrackedClass('id', 'App\\X', [
            'title' => 'Название',
            'dftRange' => ['label' => 'Толщина плёнки (DFT)', 'kind' => 'dft'],
        ]);

        self::assertSame(['title' => 'Название', 'dftRange' => 'Толщина плёнки (DFT)'], $t->fields());
        self::assertSame(['title' => 'scalar', 'dftRange' => 'dft'], $t->kinds());
    }

    public function test_retrack_accepts_new_shape_form_too(): void
    {
        $t = new TrackedClass('id', 'App\\X', ['title' => 'Название']);
        $t->retrack(['dftRange' => ['label' => 'Толщина плёнки (DFT)', 'kind' => 'dft']]);

        self::assertSame(['dftRange' => 'Толщина плёнки (DFT)'], $t->fields());
        self::assertSame(['dftRange' => 'dft'], $t->kinds());
    }
}
