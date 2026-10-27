<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\FileText;

use App\Shared\Domain\FileText\FileTextStatus;
use App\Shared\Infrastructure\FileText\FormatFileTextExtractor;
use App\Shared\Infrastructure\FileText\FormatTextExtractor;
use PHPUnit\Framework\TestCase;

final class FormatFileTextExtractorTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @unlink($path);
        }
        $this->tmp = [];
    }

    public function test_missing_file_is_error(): void
    {
        $result = $this->dispatcher($this->fake('txt'))->extract('/no/such/file.txt');

        self::assertSame(FileTextStatus::ERROR, $result->status);
    }

    public function test_oversize_file_is_error(): void
    {
        $path = $this->tempFile('txt', 'aaaaaaaaaa');
        $result = $this->dispatcher($this->fake('txt'), maxFileBytes: 3)->extract($path);

        self::assertSame(FileTextStatus::ERROR, $result->status);
    }

    public function test_image_extension_is_scan_without_parsing(): void
    {
        $path = $this->tempFile('png', 'not really a png');
        $result = $this->dispatcher($this->fake('txt'))->extract($path);

        self::assertSame(FileTextStatus::SCAN, $result->status);
    }

    public function test_unsupported_extension_is_error(): void
    {
        $path = $this->tempFile('zip', 'x');
        $result = $this->dispatcher($this->fake('txt'))->extract($path);

        self::assertSame(FileTextStatus::ERROR, $result->status);
    }

    public function test_text_extracted(): void
    {
        $path = $this->tempFile('txt', 'content');
        $result = $this->dispatcher($this->fake('txt', returns: 'привет мир'))->extract($path);

        self::assertSame(FileTextStatus::EXTRACTED, $result->status);
        self::assertSame('привет мир', $result->text);
    }

    public function test_empty_text_with_scan_semantics_is_scan(): void
    {
        $path = $this->tempFile('pdf', '%PDF');
        $result = $this->dispatcher($this->fake('pdf', returns: '   ', emptyScan: true))->extract($path);

        self::assertSame(FileTextStatus::SCAN, $result->status);
    }

    public function test_extractor_failure_is_error(): void
    {
        $path = $this->tempFile('txt', 'content');
        $result = $this->dispatcher($this->fake('txt', throws: true))->extract($path);

        self::assertSame(FileTextStatus::ERROR, $result->status);
    }

    public function test_long_text_is_truncated(): void
    {
        $path = $this->tempFile('txt', 'content');
        $result = $this->dispatcher($this->fake('txt', returns: str_repeat('a', 50)), maxTextChars: 10)->extract($path);

        self::assertSame(FileTextStatus::EXTRACTED, $result->status);
        self::assertTrue($result->truncated);
        self::assertSame(10, mb_strlen($result->text));
    }

    private function dispatcher(
        FormatTextExtractor $fake,
        int $maxFileBytes = 25000000,
        int $maxTextChars = 200000,
        int $scanMinChars = 16,
    ): FormatFileTextExtractor {
        return new FormatFileTextExtractor([$fake], $maxFileBytes, $maxTextChars, $scanMinChars);
    }

    private function fake(string $ext, string $returns = 'text', bool $emptyScan = false, bool $throws = false): FormatTextExtractor
    {
        return new class($ext, $returns, $emptyScan, $throws) implements FormatTextExtractor {
            public function __construct(
                private readonly string $ext,
                private readonly string $returns,
                private readonly bool $emptyScan,
                private readonly bool $throws,
            ) {
            }

            public function extensions(): array
            {
                return [$this->ext];
            }

            public function extract(string $path): string
            {
                if ($this->throws) {
                    throw new \RuntimeException('boom');
                }

                return $this->returns;
            }

            public function emptyMeansScan(): bool
            {
                return $this->emptyScan;
            }
        };
    }

    private function tempFile(string $ext, string $content): string
    {
        $path = sys_get_temp_dir().'/ftx_'.bin2hex(random_bytes(6)).'.'.$ext;
        file_put_contents($path, $content);
        $this->tmp[] = $path;

        return $path;
    }
}
