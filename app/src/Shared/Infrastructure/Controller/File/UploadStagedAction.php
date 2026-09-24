<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\File;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\File\StagedFileView;
use App\Shared\Application\File\StageFiles\StageFilesCommand;
use App\Shared\Application\File\StageFiles\StageFilesCommandResult;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(
    path: '/cabinet/file/stage',
    name: 'app_cabinet_file_stage',
    methods: ['POST'],
)]
final class UploadStagedAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly AuthUserFetcherInterface $authUser,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        // Клиент может прислать один файл под `files` (не `files[]`) — тогда это UploadedFile,
        // а не массив. Нормализуем в список, иначе array_filter упал бы TypeError мимо try/catch.
        $raw = $request->files->all()['files'] ?? [];
        $raw = $raw instanceof UploadedFile ? [$raw] : (array) $raw;
        $files = array_values(array_filter($raw, static fn ($f): bool => $f instanceof UploadedFile));

        try {
            /** @var StageFilesCommandResult $result */
            $result = $this->commandBus->execute(
                new StageFilesCommand($this->authUser->getAuthUserId(), $files),
            );
        } catch (AppException $e) {
            // Только доменные ошибки stage'а показываем клиенту; техническое улетает выше в
            // ExceptionListener (JSON-эндпоинт) и маскируется под 500, не утекая внутренностями.
            // Ключ `message`: глобальный ResponseDTOTransformer на 4xx читает только его.
            return new JsonResponse(['message' => $e->getMessage()], $e->getCode());
        }

        return new JsonResponse([
            'files' => array_map(
                static fn (StagedFileView $v): array => [
                    'uuid' => $v->uuid,
                    'name' => $v->name,
                    'size' => $v->size,
                    'mime' => $v->mime,
                ],
                $result->files,
            ),
        ]);
    }
}
