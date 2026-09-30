<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\RemoveFulfillment;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Service\ObligationDueCalculator;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RemoveFulfillmentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ProfileComplianceRepositoryInterface $repository,
        private ComplianceAccessControl $access,
        private ObligationDueCalculator $calculator,
        private FileStorage $storage,
    ) {
    }

    public function __invoke(RemoveFulfillmentCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        $profileCompliance = $this->repository->findByProfile($command->profileId);
        if (null === $profileCompliance) {
            throw new AppException('Учёт не найден.', Response::HTTP_NOT_FOUND);
        }

        $fileId = null;
        foreach ($profileCompliance->getRecords() as $record) {
            if ($record->getId() === $command->recordId) {
                $fileId = $record->fileId();
                break;
            }
        }

        $profileCompliance->removeRecord($command->recordId, $this->calculator);
        $this->repository->add($profileCompliance);

        if (null !== $fileId) {
            $this->storage->remove($fileId);
        }
    }
}
