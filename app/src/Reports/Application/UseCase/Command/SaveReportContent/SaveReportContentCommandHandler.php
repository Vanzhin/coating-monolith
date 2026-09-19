<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\SaveReportContent;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\File\ReportPhotoPurpose;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Service\ReportContentValidator;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сохраняет черновик содержимого отчёта: лёгкая валидация (типы заполненных значений, неполнота ОК).
 * Строгая проверка обязательных — при отправке на проверку (submitForReview, инкремент 5).
 *
 * Фото: свежезагруженные (staged) файлы привязываются к отчёту (promote, ownerId=id отчёта), а фото,
 * убранные из блока с прошлого сохранения, удаляются из хранилища. Загрузка самих байт — онлайн, через
 * общий endpoint /cabinet/file/stage; сюда приходят уже uuid-ы.
 */
final readonly class SaveReportContentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
        private ReportContentValidator $validator,
        private FileStorage $storage,
    ) {
    }

    public function __invoke(SaveReportContentCommand $command): void
    {
        $report = $this->repository->findOneById($command->reportId);
        if (null === $report) {
            throw new AppException('Отчёт не найден.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canEdit($report)) {
            throw new ForbiddenException();
        }

        $type = $report->getType();
        if (null !== $type) {
            $this->validator->validate($type, $command->content, strict: false);
        }

        $previousContent = $report->getContent();
        $this->promoteStagedPhotos($report, $command->content);

        $report->replaceContent($command->content, new \DateTimeImmutable());
        $this->repository->add($report);

        $this->removeDetachedPhotos($previousContent, $command->content);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function promoteStagedPhotos(Report $report, array $content): void
    {
        $ownerId = $report->getId();
        foreach ($this->photoUuids($content) as $uuid) {
            $file = $this->storage->get($uuid);
            if (null === $file) {
                throw new AppException('Фото не найдено или срок его хранения истёк — загрузите заново.');
            }
            if (StoredFile::STATUS_STAGED === $file->status()) {
                $this->storage->promote($uuid, ReportPhotoPurpose::Photo, $ownerId);
            }
        }
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     */
    private function removeDetachedPhotos(array $previous, array $current): void
    {
        foreach (array_diff($this->photoUuids($previous), $this->photoUuids($current)) as $uuid) {
            $this->storage->remove($uuid);
        }
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<string>
     */
    private function photoUuids(array $content): array
    {
        $items = $content['photos']['items'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $uuids = [];
        foreach ($items as $row) {
            if (is_array($row) && isset($row['file']) && is_string($row['file']) && '' !== $row['file']) {
                $uuids[] = $row['file'];
            }
        }

        return $uuids;
    }
}
