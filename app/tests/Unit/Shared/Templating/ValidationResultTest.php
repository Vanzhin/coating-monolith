<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Templating;

use App\Shared\Domain\Templating\ValidationResult;
use PHPUnit\Framework\TestCase;

final class ValidationResultTest extends TestCase
{
    public function test_empty_is_valid(): void
    {
        self::assertTrue((new ValidationResult())->isValid());
    }

    public function test_only_unused_is_valid(): void
    {
        self::assertTrue((new ValidationResult(unused: ['stray_key']))->isValid());
    }

    public function test_only_skipped_is_valid(): void
    {
        self::assertTrue((new ValidationResult(skipped: ['photo_1']))->isValid());
    }

    public function test_missing_is_invalid(): void
    {
        self::assertFalse((new ValidationResult(missing: ['object']))->isValid());
    }

    public function test_unresolved_images_is_invalid(): void
    {
        self::assertFalse((new ValidationResult(unresolvedImages: ['logo']))->isValid());
    }

    public function test_invalid_blocks_is_invalid(): void
    {
        self::assertFalse((new ValidationResult(invalidBlocks: ['opt_l1_comp_b']))->isValid());
    }
}
