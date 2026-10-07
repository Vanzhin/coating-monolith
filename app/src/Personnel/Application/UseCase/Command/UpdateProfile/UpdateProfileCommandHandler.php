<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Command\UpdateProfile;

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
use Symfony\Component\HttpFoundation\Response;

final readonly class UpdateProfileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProfileRepositoryInterface $repository,
        private ProfileMaker $maker,
        private PersonnelAccessControl $access,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(UpdateProfileCommand $command): UpdateProfileCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $profile = $this->repository->findOneById($command->id);
        if (null === $profile) {
            throw new AppException('Профиль не найден.', Response::HTTP_NOT_FOUND);
        }

        $now = new \DateTimeImmutable();

        // Снапшоты должности/организации/отдела пересобираются через тот же Maker, что и при
        // создании — связка «отдел↔организация» проверяется заново (могли сменить и то, и другое).
        $profile->changeFullName(FullName::of($command->lastName, $command->firstName, $command->middleName), $now);
        $profile->changePosition($this->maker->resolvePosition($command->positionId), $now);
        $profile->changeDepartment($this->maker->resolveDepartment($command->departmentId, $command->organizationId), $now);
        $profile->changeOrganization($this->maker->resolveOrganization($command->organizationId), $now);
        $profile->changeSizes(
            Sizes::fromInput(
                $command->clothing,
                $command->shoes,
                $command->headgear,
                $command->respirator,
                $command->gloves,
                $command->height,
                $this->resolveGender($command->gender),
            ),
            $now,
        );
        $profile->changePersonnelNumber($command->personnelNumber, $now);
        $profile->changeHiredAt($command->hiredAt, $now);
        $profile->changeBirthDate($command->birthDate, $now);

        $this->repository->add($profile);
        $this->eventBus->execute(new ProfileSaved($profile->getId()));

        return new UpdateProfileCommandResult($profile->getId());
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
