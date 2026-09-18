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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

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
        $files = array_values(array_filter(
            $request->files->all()['files'] ?? [],
            static fn ($f): bool => $f instanceof UploadedFile,
        ));

        try {
            /** @var StageFilesCommandResult $result */
            $result = $this->commandBus->execute(
                new StageFilesCommand($this->authUser->getAuthUserId(), $files),
            );
        } catch (\Exception $e) {
            $status = $e instanceof AppException ? $e->getCode() : Response::HTTP_BAD_REQUEST;

            return new JsonResponse(['error' => $e->getMessage()], $status);
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
