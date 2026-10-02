<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\CreateProfile;

use App\Personnel\Application\Service\AccessControl\PersonnelAccessControl;
use App\Personnel\Application\Service\ProfileMaker;
use App\Personnel\Domain\Aggregate\Profile\FullName;
use App\Personnel\Domain\Aggregate\Profile\Gender;
use App\Personnel\Domain\Aggregate\Profile\Sizes;
use App\Personnel\Domain\Event\ProfileSaved;
use App\Personnel\Domain\Repository\ProfileRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class CreateProfileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProfileRepositoryInterface $repository,
        private ProfileMaker $maker,
        private PersonnelAccessControl $access,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(CreateProfileCommand $command): CreateProfileCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $fullName = FullName::of($command->lastName, $command->firstName, $command->middleName);
        $sizes = Sizes::fromInput(
            $command->clothing,
            $command->shoes,
            $command->headgear,
            $command->respirator,
            $command->gloves,
            $command->height,
            $this->resolveGender($command->gender),
        );

        $profile = $this->maker->make(
            $command->userUlid,
            $fullName,
            $command->positionId,
            $command->organizationId,
            $command->departmentId,
            $sizes,
            $command->personnelNumber,
            $command->hiredAt,
        );
        $this->repository->add($profile);
        $this->eventBus->execute(new ProfileSaved($profile->getId()));

        return new CreateProfileCommandResult($profile->getId());
    }

    /**
     * Вход — сырая строка формы/команды: tryFrom вместо from, чтобы невалидное значение
     * возвращало понятную 422-ошибку, а не необработанный \ValueError.
     */
    private function resolveGender(?string $gender): ?Gender
    {
        if (null === $gender || '' === $gender) {
            return null;
        }

        $resolved = Gender::tryFrom($gender);
        if (null === $resolved) {
            throw new AppException('Недопустимое значение пола.');
        }

        return $resolved;
    }
}
