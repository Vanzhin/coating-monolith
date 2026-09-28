<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Position;

use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Создание должности «на лету» (напр. из typeahead формы профиля сотрудника: название).
 * Возвращает {id, title}. Авторизация и уникальность title — в домене/хендлере.
 */
#[Route(path: '/cabinet/personnel/position/quick', name: 'app_cabinet_personnel_position_quick', methods: ['POST'])]
final class QuickCreateAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $title = is_array($payload) ? trim((string) ($payload['title'] ?? '')) : '';

        try {
            $result = $this->commandBus->execute(new CreatePositionCommand($title));
            \assert($result instanceof CreatePositionCommandResult);
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['id' => $result->id, 'title' => $result->title], Response::HTTP_CREATED);
    }
}
