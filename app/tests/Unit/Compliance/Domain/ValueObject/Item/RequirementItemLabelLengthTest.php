<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\ValueObject\Cadence;
use App\Compliance\Domain\ValueObject\Item\NonMaterialItem;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

/**
 * Длина наименования позиции ограничена сверху, чтобы проекция (TrackedObligation.label) её вмещала —
 * иначе пересборка падает на INSERT (varchar) и молча откатывается. Граница = 1000 символов.
 */
final class RequirementItemLabelLengthTest extends TestCase
{
    public function test_label_up_to_1000_chars_is_accepted(): void
    {
        $label = str_repeat('а', 1000);
        $item = new NonMaterialItem($label, new Cadence(CadenceKind::Once), 'основание');
        self::assertSame($label, $item->label());
    }

    public function test_label_longer_than_1000_chars_rejected(): void
    {
        $this->expectException(AppException::class);
        new NonMaterialItem(str_repeat('а', 1001), new Cadence(CadenceKind::Once), 'основание');
    }
}
