<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Application\UseCase\Command\CreateInquiry;

use App\Inquiries\Application\UseCase\Command\CreateInquiry\CreateInquiryCommand;
use App\Inquiries\Application\UseCase\Command\CreateInquiry\CreateInquiryCommandResult;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CreateInquiryCommandHandlerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function test_creates_received_inquiry_owned_by_actor(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $container = static::getContainer();
        $bus = $container->get(CommandBusInterface::class);
        $repo = $container->get(InquiryRepositoryInterface::class);
        \assert($bus instanceof CommandBusInterface);
        \assert($repo instanceof InquiryRepositoryInterface);

        $result = $bus->execute(new CreateInquiryCommand(InquiryType::SystemSelection, 'нужна система для этанола', []));
        \assert($result instanceof CreateInquiryCommandResult);

        $inquiry = $repo->findOneById($result->id);
        self::assertNotNull($inquiry);
        self::assertSame(InquiryStatus::Received, $inquiry->getStatus());
        self::assertSame('system', $inquiry->getOwnerId());
        self::assertSame('нужна система для этанола', $inquiry->getRawText());
    }

    public function test_rejects_non_manager(): void
    {
        self::bootKernel();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->expectException(ForbiddenException::class);
        $bus->execute(new CreateInquiryCommand(InquiryType::SystemSelection, 'текст', []));
    }
}
