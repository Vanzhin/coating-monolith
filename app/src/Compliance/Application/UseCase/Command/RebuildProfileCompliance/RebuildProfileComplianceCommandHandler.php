<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RebuildProfileCompliance;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class RebuildProfileComplianceCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private ComplianceProjectionRebuilder $rebuilder,
    ) {
    }

    public function __invoke(RebuildProfileComplianceCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $this->rebuilder->rebuildForProfile($command->profileId);
    }
}
