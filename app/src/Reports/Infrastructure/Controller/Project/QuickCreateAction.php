<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Project;

use App\Reports\Application\UseCase\Command\CreateProject\CreateProjectCommand;
use App\Reports\Application\UseCase\Command\CreateProject\CreateProjectCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Создание проекта «на лету» из формы отчёта. Проекту в домене обязателен контрагент — его id
 * приходит от выбранного «Заказчика». Нет заказчика → 422 с подсказкой. Возвращает {id,title}.
 */
#[Route(path: '/cabinet/reports/project/quick', name: 'app_cabinet_reports_project_quick', methods: ['POST'])]
final class QuickCreateAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $title = is_array($payload) ? trim((string) ($payload['title'] ?? '')) : '';
        $counterpartyId = is_array($payload) ? trim((string) ($payload['counterpartyId'] ?? '')) : '';

        if ('' === $counterpartyId) {
            return new JsonResponse(['message' => 'Сначала выберите заказчика — проект создаётся под ним.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = $this->commandBus->execute(new CreateProjectCommand($title, $counterpartyId));
            \assert($result instanceof CreateProjectCommandResult);
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['id' => $result->id, 'title' => $result->title], Response::HTTP_CREATED);
    }
}
