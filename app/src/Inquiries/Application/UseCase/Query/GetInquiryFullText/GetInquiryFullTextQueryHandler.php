<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiryFullText;

use App\Inquiries\Application\DTO\View\InquiryFileText;
use App\Inquiries\Application\DTO\View\InquiryFullText;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\FileText\FileTextExtractor;
use App\Shared\Domain\FileText\FileTextStatus;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Полный текст запроса для ленивой модалки: тело + пере-извлечённый текст каждого вложения. Пере-
 * извлекаем по запросу (в БД не хранится), поэтому и ленивый эндпоинт. Доступ — только админ.
 */
final readonly class GetInquiryFullTextQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
        private FileStorage $storage,
        private FileTextExtractor $extractor,
    ) {
    }

    public function __invoke(GetInquiryFullTextQuery $query): ?InquiryFullText
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($query->inquiryId);
        if (null === $inquiry) {
            return null;
        }

        $files = [];
        foreach ($inquiry->getAttachmentFileIds()->getList() as $fileId) {
            $stored = $this->storage->get($fileId);
            if (null === $stored) {
                $files[] = new InquiryFileText($fileId, 'файл недоступен', '');

                continue;
            }

            try {
                $tmp = $this->storage->toLocalTempFile($fileId);
            } catch (\Throwable) {
                $files[] = new InquiryFileText($stored->originalName(), 'файл недоступен', '');

                continue;
            }

            try {
                $text = $this->extractor->extract($tmp, $stored->mime());
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }

            $files[] = new InquiryFileText(
                $stored->originalName(),
                $this->statusLabel($text->status),
                $text->isExtracted() ? $text->text : ($text->error ?? ''),
            );
        }

        return new InquiryFullText($inquiry->getRawText(), $files);
    }

    private function statusLabel(FileTextStatus $status): string
    {
        return match ($status) {
            FileTextStatus::EXTRACTED => 'текст извлечён',
            FileTextStatus::SCAN => 'скан — текстового слоя нет',
            FileTextStatus::ERROR => 'не прочитан',
        };
    }
}
