<?php

declare(strict_types=1);

namespace App\Tests\Unit\Certificates\Domain\Aggregate\Document;

use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use PHPUnit\Framework\TestCase;

class DocumentKindTest extends TestCase
{
    public function test_every_document_kind_has_label(): void
    {
        foreach (DocumentKind::cases() as $kind) {
            self::assertNotSame('', $kind->label());
        }
        self::assertCount(4, DocumentKind::cases());
    }

    public function test_every_reference_type_has_label(): void
    {
        foreach (ReferenceType::cases() as $type) {
            self::assertNotSame('', $type->label());
        }
        self::assertCount(2, ReferenceType::cases());
    }
}
