<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\WriteOffAct;

use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Скачать скан подписанного акта списания. Просмотр — авторизованным (security.yaml). Акт кросс-персон — по его id. */
#[Route(
    path: '/cabinet/compliance/writeoff/{actId}/scan',
    name: 'app_cabinet_compliance_writeoff_scan',
    methods: ['GET'],
)]
final class DownloadScanAction extends AbstractController
{
    public function __construct(
        private readonly WriteOffActRepositoryInterface $writeOffActRepository,
        private readonly FileStorage $storage,
    ) {
    }

    public function __invoke(string $actId): Response
    {
        $scanFileId = $this->writeOffActRepository->findById($actId)?->scanFileId();
        if (null === $scanFileId) {
            throw new AppException('Скан акта списания не найден.', Response::HTTP_NOT_FOUND);
        }

        $file = $this->storage->get($scanFileId);
        if (null === $file) {
            throw new AppException('Скан акта списания не найден.', Response::HTTP_NOT_FOUND);
        }

        $stream = $this->storage->readStream($scanFileId);
        $response = new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        });
        $name = $file->originalName();
        $response->headers->set('Content-Type', $file->mime());
        $response->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $this->asciiFallback($name)),
        );

        return $response;
    }

    private function asciiFallback(string $fileName): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '', $fileName) ?? '';
        $ascii = trim(str_replace(['/', '\\', '%', '"'], '', $ascii));

        return '' === $ascii ? 'act' : $ascii;
    }
}
