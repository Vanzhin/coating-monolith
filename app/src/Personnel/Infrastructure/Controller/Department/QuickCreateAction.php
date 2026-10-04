<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Создание отдела «на лету» из формы профиля. Отдел — подразделение организации, потому companyId
 * обязателен (приходит от выбранной организации через водопад). Нет организации → 422. Возвращает {id,title}.
 */
#[Route(path: '/cabinet/personnel/department/quick', name: 'app_cabinet_personnel_department_quick', methods: ['POST'])]
final class QuickCreateAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $title = is_array($payload) ? trim((string) ($payload['title'] ?? '')) : '';
        $companyId = is_array($payload) ? trim((string) ($payload['companyId'] ?? '')) : '';

        if ('' === $companyId) {
            return new JsonResponse(['message' => 'Сначала выберите организацию — отдел создаётся в ней.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            /** @var CreateDepartmentCommandResult $result */
            $result = $this->commandBus->execute(new CreateDepartmentCommand($companyId, $title));
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['id' => $result->id, 'title' => $result->title], Response::HTTP_CREATED);
    }
}
