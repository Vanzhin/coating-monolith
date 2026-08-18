<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command\CreateColor;

use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;

final readonly class CreateColorCommandHandler implements CommandHandlerInterface
{
    public function __construct(private ColorRepositoryInterface $repository)
    {
    }

    public function __invoke(CreateColorCommand $command): CreateColorCommandResult
    {
        // Конструктор сам валидирует имя/RAL и выводит hex из RAL — строим цвет,
        // потом сверяем уникальность пары (название, hex) по уже нормализованным значениям.
        $color = new Color(UuidService::generateUuid(), $command->name, $command->ral, $command->hex);

        $existing = $this->repository->findOneByNameAndHex($color->getName(), $color->getHex());
        if (null !== $existing) {
            throw new AppException(sprintf('Цвет «%s» (%s) уже есть в справочнике.', $color->getName(), $color->getHex()));
        }

        $this->repository->add($color);

        return new CreateColorCommandResult($color->getId(), $color->getName(), $color->getRal(), $color->getHex());
    }
}
