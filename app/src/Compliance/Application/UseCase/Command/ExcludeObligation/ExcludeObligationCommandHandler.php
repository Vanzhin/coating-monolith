<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\ExcludeObligation;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\ComplianceObligationProjector;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * «Убрать позицию» с карточки выдачи: ключ уходит в excluded_keys агрегата, проектор перестаёт выводить строку —
 * позиция пропадает с /issue и дашборда. Факты журнала (выдачи/списания) НЕ трогаем — история сохраняется.
 * Проекцию пересобираем сразу (строка исчезает без ожидания воркера), точечно по требованию.
 */
final readonly class ExcludeObligationCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ProfileComplianceRepositoryInterface $repository,
        private ComplianceObligationProjector $projector,
    ) {
    }

    public function __invoke(ExcludeObligationCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId)
            ?? throw new AppException('Учёт по сотруднику не создан.');

        $profileCompliance->excludeObligation($command->obligationKey);
        $this->repository->add($profileCompliance);

        $this->projector->projectProfile($command->profileId, $command->requirementId);
    }
}
