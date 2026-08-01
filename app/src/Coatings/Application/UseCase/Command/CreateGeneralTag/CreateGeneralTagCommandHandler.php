<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateGeneralTag;

use App\Coatings\Domain\Aggregate\Tag\Specification\TagSpecification;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class CreateGeneralTagCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private TagRepositoryInterface $repository,
        private TagSpecification $specification,
    ) {
    }

    public function __invoke(CreateGeneralTagCommand $command): CreateGeneralTagCommandResult
    {
        $title = trim($command->title);
        if ('' === $title) {
            throw new AppException('Название тега не может быть пустым.');
        }

        $existing = $this->repository->findOneByTitleAndType($title, Tag::TYPE_GENERAL);
        if (null !== $existing) {
            throw new AppException(sprintf('Тег «%s» уже существует.', $title));
        }

        $tag = new Tag($title, $this->specification, Tag::TYPE_GENERAL);
        $this->repository->add($tag);

        return new CreateGeneralTagCommandResult($tag->getId(), $tag->getTitle());
    }
}
