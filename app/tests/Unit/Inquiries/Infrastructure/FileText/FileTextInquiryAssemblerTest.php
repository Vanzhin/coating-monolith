<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Infrastructure\FileText;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Infrastructure\Api\NetAngels\SystemPromptBuilder;
use App\Inquiries\Infrastructure\FileText\FileTextInquiryAssembler;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\File\FilePurpose;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\FileText\FileText;
use App\Shared\Domain\FileText\FileTextExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Uid\Uuid;

final class FileTextInquiryAssemblerTest extends TestCase
{
    public function test_labels_body_and_attachment_sources_in_order(): void
    {
        $inquiry = $this->inquiry('тело запроса: нефть 90', ['att-1']);

        $storage = $this->storage(['att-1' => $this->stored('att-1', 'опросник.pdf')]);
        $extractor = $this->extractor(['/tmp/att-1' => FileText::extracted('опросник: нефть 80', false)]);

        $assembled = (new FileTextInquiryAssembler($storage, $extractor))->assemble($inquiry);

        self::assertSame(
            "=== Тело письма ===\nтело запроса: нефть 90\n\n=== Вложение: опросник.pdf ===\nопросник: нефть 80",
            $assembled->text,
        );
        self::assertSame([], $assembled->warnings);
    }

    public function test_labels_each_of_several_attachments_in_order(): void
    {
        $inquiry = $this->inquiry('тело', ['att-1', 'att-2']);

        $storage = $this->storage([
            'att-1' => $this->stored('att-1', 'опросник.pdf'),
            'att-2' => $this->stored('att-2', 'тз.docx'),
        ]);
        $extractor = $this->extractor([
            '/tmp/att-1' => FileText::extracted('текст один', false),
            '/tmp/att-2' => FileText::extracted('текст два', false),
        ]);

        $assembled = (new FileTextInquiryAssembler($storage, $extractor))->assemble($inquiry);

        self::assertSame(
            "=== Тело письма ===\nтело\n\n=== Вложение: опросник.pdf ===\nтекст один\n\n=== Вложение: тз.docx ===\nтекст два",
            $assembled->text,
        );
    }

    public function test_empty_body_starts_text_at_attachment(): void
    {
        $inquiry = $this->inquiry('', ['att-1']);

        $storage = $this->storage(['att-1' => $this->stored('att-1', 'опросник.pdf')]);
        $extractor = $this->extractor(['/tmp/att-1' => FileText::extracted('только вложение', false)]);

        $assembled = (new FileTextInquiryAssembler($storage, $extractor))->assemble($inquiry);

        self::assertSame("=== Вложение: опросник.pdf ===\nтолько вложение", $assembled->text);
        self::assertStringNotContainsString('Тело письма', $assembled->text);
    }

    public function test_scan_attachment_is_warned_and_not_labeled(): void
    {
        $inquiry = $this->inquiry('тело', ['scan-1']);

        $storage = $this->storage(['scan-1' => $this->stored('scan-1', 'скан.pdf')]);
        $extractor = $this->extractor(['/tmp/scan-1' => FileText::scan()]);

        $assembled = (new FileTextInquiryAssembler($storage, $extractor))->assemble($inquiry);

        self::assertSame("=== Тело письма ===\nтело", $assembled->text);
        self::assertStringNotContainsString('Вложение', $assembled->text);
        self::assertNotEmpty($assembled->warnings);
    }

    public function test_source_markers_match_extraction_prompt(): void
    {
        $prompt = (new SystemPromptBuilder())->build();

        // Иначе модель не распознает источник значения — склейка и инструкция разъедутся молча.
        self::assertStringContainsString(FileTextInquiryAssembler::BODY_MARKER, $prompt);
        self::assertStringContainsString(FileTextInquiryAssembler::ATTACHMENT_MARKER_PREFIX, $prompt);
    }

    /** @param list<string> $attachmentIds */
    private function inquiry(string $body, array $attachmentIds): Inquiry
    {
        return new Inquiry(
            Uuid::v4(),
            InquiryType::SystemSelection,
            'owner',
            $body,
            new StringCollection(...$attachmentIds),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
    }

    private function stored(string $id, string $name): StoredFile
    {
        return StoredFile::staged(
            $id,
            'uploader',
            $name,
            'application/pdf',
            'pdf',
            1024,
            new \DateTimeImmutable('2026-01-01 10:00:00'),
            new \DateTimeImmutable('2026-01-02 10:00:00'),
        );
    }

    /** @param array<string, StoredFile> $byId */
    private function storage(array $byId): FileStorage
    {
        return new class($byId) implements FileStorage {
            /** @param array<string, StoredFile> $byId */
            public function __construct(private readonly array $byId)
            {
            }

            public function get(string $uuid): ?StoredFile
            {
                return $this->byId[$uuid] ?? null;
            }

            public function toLocalTempFile(string $uuid): string
            {
                return '/tmp/'.$uuid;
            }

            public function store(FilePurpose $purpose, string $ownerId, UploadedFile $file): StoredFile
            {
                throw new \LogicException('not used');
            }

            public function stage(string $uploaderId, UploadedFile $file): StoredFile
            {
                throw new \LogicException('not used');
            }

            public function promote(string $uuid, FilePurpose $purpose, string $ownerId): StoredFile
            {
                throw new \LogicException('not used');
            }

            /** @return StoredFile[] */
            public function byOwner(FilePurpose $purpose, string $ownerId): array
            {
                return [];
            }

            public function readStream(string $uuid)
            {
                throw new \LogicException('not used');
            }

            public function localPath(string $uuid): string
            {
                throw new \LogicException('not used');
            }

            public function remove(string $uuid): void
            {
            }

            public function removeByOwner(FilePurpose $purpose, string $ownerId): void
            {
            }

            public function purgeExpired(): int
            {
                return 0;
            }
        };
    }

    /** @param array<string, FileText> $byPath */
    private function extractor(array $byPath): FileTextExtractor
    {
        return new class($byPath) implements FileTextExtractor {
            /** @param array<string, FileText> $byPath */
            public function __construct(private readonly array $byPath)
            {
            }

            public function extract(string $path, ?string $mimeType = null): FileText
            {
                return $this->byPath[$path] ?? FileText::error('нет фикстуры');
            }
        };
    }
}
