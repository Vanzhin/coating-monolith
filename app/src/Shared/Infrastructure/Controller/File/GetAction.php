<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\File;

use App\Shared\Application\File\FileAccessControl;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Общая отдача сохранённого файла по uuid (inline) — превью/просмотр для всех контекстов (фото
 * отчётов и т.п.). Единый сервис FileStorage. Доступ — per-назначение через tagged FileAccessControl
 * (напр. фото отчёта: владелец/админ); назначение без контроля не отдаётся (deny by default).
 * Скачивание «вложением» с именем — у контекста (Certificates DownloadAction), тут inline для показа.
 */
#[Route(
    path: '/cabinet/file/{uuid}',
    name: 'app_cabinet_file_get',
    methods: ['GET'],
    requirements: ['uuid' => '[0-9a-f\-]{36}'],
)]
final class GetAction extends AbstractController
{
    /**
     * @param iterable<FileAccessControl> $accessControls
     */
    public function __construct(
        private readonly FileStorage $storage,
        #[AutowireIterator('app.file_access_control')]
        private readonly iterable $accessControls,
    ) {
    }

    public function __invoke(string $uuid): Response
    {
        $file = $this->storage->get($uuid);
        if (null === $file) {
            throw new AppException('Файл не найден.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->canView($file)) {
            throw new ForbiddenException();
        }

        $stream = $this->storage->readStream($uuid);
        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });
        $response->headers->set('Content-Type', $file->mime());
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_INLINE,
            $file->originalName(),
            'file.'.$file->extension(),
        ));

        return $response;
    }

    private function canView(StoredFile $file): bool
    {
        foreach ($this->accessControls as $control) {
            if ($control->supports($file->purpose())) {
                return $control->canView($file);
            }
        }

        return false; // назначение без объявленного контроля не отдаём
    }
}
