<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Counterparty;

use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Создание контрагента «на лету» из формы отчёта (поиск-или-создай, как теги). Возвращает {id,title}.
 * Авторизация — в CreateCounterparty-хендлере.
 */
#[Route(path: '/cabinet/reports/counterparty/quick', name: 'app_cabinet_reports_counterparty_quick', methods: ['POST'])]
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
            $result = $this->commandBus->execute(new CreateCounterpartyCommand($title));
            \assert($result instanceof CreateCounterpartyCommandResult);
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['id' => $result->id, 'title' => $result->title], Response::HTTP_CREATED);
    }
}
