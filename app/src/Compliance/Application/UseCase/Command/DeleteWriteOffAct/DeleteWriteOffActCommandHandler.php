<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\DeleteWriteOffAct;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/** Удалить черновик акта списания (самостоятельный кросс-персон агрегат). Подписанный — упрётся в assertMutable. */
final readonly class DeleteWriteOffActCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ComplianceAccessControl $access,
        private WriteOffActRepositoryInterface $writeOffActRepository,
    ) {
    }

    public function __invoke(DeleteWriteOffActCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $act = $this->writeOffActRepository->findById($command->writeOffActId);
        if (null === $act) {
            return; // уже нет — идемпотентно
        }
        $act->assertMutable(); // оформленный акт удалить нельзя
        $this->writeOffActRepository->remove($act);
    }
}
