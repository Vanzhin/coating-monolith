<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\File;

use App\Compliance\Domain\File\RequirementTemplatePurpose;
use App\Shared\Domain\File\FilePurpose;
use PHPUnit\Framework\TestCase;

final class RequirementTemplatePurposeTest extends TestCase
{
    public function test_template_purpose_metadata(): void
    {
        $purpose = RequirementTemplatePurpose::Template;

        self::assertInstanceOf(FilePurpose::class, $purpose);
        self::assertSame('requirement_template', $purpose->value);
        self::assertSame('compliance.requirement_template', $purpose->key());
        self::assertSame('compliance/requirement_template', $purpose->storagePrefix());

        $mimes = $purpose->constraints()->mimeTypes();
        self::assertContains('application/vnd.openxmlformats-officedocument.wordprocessingml.document', $mimes, 'docx разрешён');
        self::assertContains('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $mimes, 'xlsx разрешён (движок умеет оба)');
    }
}
