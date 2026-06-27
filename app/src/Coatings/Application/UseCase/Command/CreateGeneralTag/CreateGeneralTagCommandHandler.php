<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateGeneralTag;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class CreateGeneralTagCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingTagRepositoryInterface $repository,
        private CoatingTagSpecification $specification,
    ) {
    }

    public function __invoke(CreateGeneralTagCommand $command): CreateGeneralTagCommandResult
    {
        $title = trim($command->title);
        if ($title === '') {
            throw new AppException('Название тега не может быть пустым.');
        }

        $existing = $this->repository->findOneByTitleAndType($title, CoatingTag::TYPE_GENERAL);
        if ($existing !== null) {
            throw new AppException(sprintf('Тег «%s» уже существует.', $title));
        }

        $tag = new CoatingTag($title, $this->specification, CoatingTag::TYPE_GENERAL);
        $this->repository->add($tag);

        return new CreateGeneralTagCommandResult($tag->getId(), $tag->getTitle());
    }
}
