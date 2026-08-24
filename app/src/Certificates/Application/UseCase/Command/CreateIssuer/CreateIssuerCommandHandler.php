<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\CreateIssuer;

use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CreateIssuerCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private IssuerRepositoryInterface $repository,
        private IssuerSpecification $specification,
    ) {
    }

    public function __invoke(CreateIssuerCommand $command): CreateIssuerCommandResult
    {
        $issuer = new Issuer(Uuid::v7(), $command->title, $this->specification);
        $this->repository->add($issuer);

        return new CreateIssuerCommandResult($issuer->getId(), $issuer->getTitle());
    }
}
