<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateColor;

use App\Coatings\Application\Service\AccessControl\CoatingAccessControl;
use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;

final readonly class CreateColorCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ColorRepositoryInterface $repository,
        private CoatingAccessControl $access,
    ) {
    }

    public function __invoke(CreateColorCommand $command): CreateColorCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        // Конструктор сам валидирует имя/RAL и выводит hex из RAL — строим цвет,
        // потом сверяем уникальность пары (название, hex) по уже нормализованным значениям.
        $color = new Color(UuidService::generateUuid(), $command->name, $command->ral, $command->hex);

        $existing = $this->repository->findOneByNameAndHex($color->getName(), $color->getHex());
        if (null !== $existing) {
            throw new AppException(sprintf('Цвет «%s» (%s) уже есть в справочнике.', $color->getName(), $color->getHex()));
        }

        $this->repository->add($color);

        return new CreateColorCommandResult($color->getId(), $color->getName(), $color->getRal(), $color->getHex(), $color->label());
    }
}
