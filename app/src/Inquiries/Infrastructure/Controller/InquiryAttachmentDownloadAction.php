<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\DTO\View\InquiryView;
use App\Inquiries\Application\UseCase\Query\GetInquiry\GetInquiryQuery;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\File\FileStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Отдаёт вложение запроса (inline). Доступ — через GetInquiryQuery (admin-гейт) + проверка, что файл
 * принадлежит ИМЕННО этому запросу (иначе по id запроса качали бы любой файл). Scoped, а не общий
 * File/GetAction: вложения пока staged без purpose, общий сервис им откажет (deny-by-default).
 */
#[Route(
    path: '/cabinet/inquiry/{id}/attachment/{fileId}',
    name: 'app_cabinet_inquiry_attachment',
    methods: ['GET'],
    requirements: ['fileId' => '[0-9a-f\-]{36}'],
)]
final class InquiryAttachmentDownloadAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly FileStorage $storage,
    ) {
    }

    public function __invoke(string $id, string $fileId): Response
    {
        $view = $this->queryBus->execute(new GetInquiryQuery($id));
        if (!$view instanceof InquiryView) {
            throw $this->createNotFoundException('Запрос не найден.');
        }

        $belongs = false;
        foreach ($view->attachments as $attachment) {
            if ($attachment->id === $fileId) {
                $belongs = true;
                break;
            }
        }
        if (!$belongs) {
            throw $this->createNotFoundException('Вложение не найдено.');
        }

        $stored = $this->storage->get($fileId);
        if (null === $stored) {
            throw $this->createNotFoundException('Файл недоступен (возможно, удалён по сроку).');
        }

        $stream = $this->storage->readStream($fileId);

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        }, Response::HTTP_OK, [
            'Content-Type' => $stored->mime(),
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $stored->originalName(), 'file'),
        ]);
    }
}
