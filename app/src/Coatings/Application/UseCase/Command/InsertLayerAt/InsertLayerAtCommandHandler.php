<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\InsertLayerAt;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class InsertLayerAtCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingSystemRepositoryInterface $repo,
        private CoatingRepositoryInterface $coatingRepo,
        private CoatingSystemChainValidator $chainValidator,
    ) {
    }

    public function __invoke(InsertLayerAtCommand $cmd): InsertLayerAtCommandResult
    {
        $system = $this->repo->findById(Uuid::fromString($cmd->systemId));

        if (null === $system) {
            throw new AppException(sprintf('Система покрытий с id %s не найдена.', $cmd->systemId), 404);
        }

        $coating = $this->coatingRepo->findOneById($cmd->coatingId);

        if (null === $coating) {
            throw new AppException(sprintf('Покрытие с id %s не найдено.', $cmd->coatingId), 404);
        }

        $system->setChainValidator($this->chainValidator);
        $layer = $system->insertLayerAt($cmd->position, $coating, $cmd->dft);
        $this->repo->save($system);

        return new InsertLayerAtCommandResult($layer->getId());
    }
}
