<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\User;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Application\UseCase\Command\CreateUser\CreateUserCommand;
use App\Users\Application\UseCase\Command\CreateUser\CreateUserCommandResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Создание пользователя «на лету» из формы профиля сотрудника (модалка «Новый пользователь»: email).
 * Пароль не задаём — учётка заводится, вход сотрудник получит через сброс пароля. Возвращает {id, title:email}.
 * Уникальность email — в хендлере. id = ulid для привязки профиля.
 */
#[Route(path: '/cabinet/users/quick', name: 'app_cabinet_users_quick', methods: ['POST'])]
final class QuickCreateAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $payload = json_decode($request->getContent(), true);
        $email = is_array($payload) ? trim((string) ($payload['email'] ?? '')) : '';

        try {
            /** @var CreateUserCommandResult $result */
            $result = $this->commandBus->execute(new CreateUserCommand($email, null));
        } catch (AppException $e) {
            return new JsonResponse(['message' => $e->getMessage()], $e->getCode() ?: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['id' => $result->ulid, 'title' => $email], Response::HTTP_CREATED);
    }
}
