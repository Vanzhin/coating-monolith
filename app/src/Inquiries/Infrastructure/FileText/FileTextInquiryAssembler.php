<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\FileText;

use App\Inquiries\Application\DTO\AssembledText;
use App\Inquiries\Application\Port\InquiryTextAssembler;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\FileText\FileTextExtractor;
use App\Shared\Domain\FileText\FileTextStatus;

/**
 * Собирает текст: тело запроса + механически извлечённый текст каждого вложения (Shared FileTextExtractor
 * по локальной временной копии из хранилища). Ничего не роняет на кривом файле — скан/ошибка/обрезка
 * превращаются в предупреждение, остальные части всё равно уходят в подбор.
 */
final readonly class FileTextInquiryAssembler implements InquiryTextAssembler
{
    public function __construct(private FileStorage $storage, private FileTextExtractor $extractor)
    {
    }

    public function assemble(Inquiry $inquiry): AssembledText
    {
        $parts = [];
        $warnings = [];

        $body = trim($inquiry->getRawText());
        if ('' !== $body) {
            $parts[] = $body;
        }

        foreach ($inquiry->getAttachmentFileIds()->getList() as $fileId) {
            $stored = $this->storage->get($fileId);
            if (null === $stored) {
                $warnings[] = sprintf('Файл %s не найден в хранилище.', $fileId);

                continue;
            }

            $name = $stored->originalName();
            try {
                $tmp = $this->storage->toLocalTempFile($fileId);
            } catch (\Throwable $e) {
                $warnings[] = sprintf('Файл «%s» недоступен: %s', $name, $e->getMessage());

                continue;
            }

            try {
                $fileText = $this->extractor->extract($tmp, $stored->mime());
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            if ($fileText->isExtracted()) {
                $parts[] = $fileText->text;
                if ($fileText->truncated) {
                    $warnings[] = sprintf('Файл «%s» обрезан по лимиту длины — учтён частично.', $name);
                }
            } elseif (FileTextStatus::SCAN === $fileText->status) {
                $warnings[] = sprintf('Файл «%s» — скан без текстового слоя, в подбор не попал.', $name);
            } else {
                $warnings[] = sprintf('Файл «%s» не прочитан: %s', $name, $fileText->error ?? 'ошибка');
            }
        }

        return new AssembledText(implode("\n\n", $parts), $warnings);
    }
}
