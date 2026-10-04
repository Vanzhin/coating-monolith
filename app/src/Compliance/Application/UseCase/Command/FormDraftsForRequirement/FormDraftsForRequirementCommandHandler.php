<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\FormDraftsForRequirement;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Event\RequirementChanged;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Кнопка «сформировать для всех»: проверяем права и уводим РАБОТУ (пересборка проекции покрытых людей +
 * черновики) в воркер тем же событием, что и смена нормы — людей может быть много, запрос ждать не должен.
 */
final readonly class FormDraftsForRequirementCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private EventBusInterface $eventBus,
    ) {
    }

    public function __invoke(FormDraftsForRequirementCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $this->eventBus->execute(new RequirementChanged($command->requirementId));
    }
}
