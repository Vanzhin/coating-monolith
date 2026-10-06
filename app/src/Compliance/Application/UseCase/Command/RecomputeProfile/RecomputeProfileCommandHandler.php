<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RecomputeProfile;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Синхронно пересобирает проекцию учёта человека из нормы (та же логика, что служебная консоль и async-пересчёт).
 * Для случая, когда событийный пересчёт пропущен/упал — админ чинит руками из кабинета.
 */
final readonly class RecomputeProfileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ComplianceProjectionRebuilder $rebuilder,
    ) {
    }

    public function __invoke(RecomputeProfileCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $this->rebuilder->rebuild(profileIds: new StringCollection($command->profileId));
    }
}
