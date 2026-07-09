<?php

declare(strict_types=1);

namespace App\Users\Application\UseCase\Command\CreateUser;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Factory\UserFactory;
use App\Users\Domain\Repository\UserRepositoryInterface;

readonly class CreateUserCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
        private UserFactory $userFactory,
    ) {
    }

    public function __invoke(CreateUserCommand $command): CreateUserCommandResult
    {
        if ($this->userRepository->getByEmail($command->email) !== null) {
            throw new AppException('Пользователь с такой почтой уже существует.');
        }

        $user = $this->userFactory->create($command->email, $command->password);
        $this->userRepository->add($user);

        return new CreateUserCommandResult($user->getUlid());
    }
}
