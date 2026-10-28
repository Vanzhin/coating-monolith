<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Application\UseCase\Command\SetInquiryComment;

use App\Inquiries\Application\UseCase\Command\SetInquiryComment\SetInquiryCommentCommand;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Factory\InquiryFactory;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class SetInquiryCommentCommandHandlerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function test_saves_comment(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $container = static::getContainer();
        $bus = $container->get(CommandBusInterface::class);
        $repo = $container->get(InquiryRepositoryInterface::class);
        $factory = $container->get(InquiryFactory::class);
        $em = $container->get(EntityManagerInterface::class);
        \assert($bus instanceof CommandBusInterface);
        \assert($repo instanceof InquiryRepositoryInterface);
        \assert($factory instanceof InquiryFactory);
        \assert($em instanceof EntityManagerInterface);

        $inquiry = $factory->create(InquiryType::SystemSelection, 'owner', 'текст', new StringCollection(), new \DateTimeImmutable());
        $repo->add($inquiry);
        $id = $inquiry->getId();

        $bus->execute(new SetInquiryCommentCommand($id, 'перезвонил клиенту'));
        $em->clear();

        $loaded = $repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame('перезвонил клиенту', $loaded->getComment());
    }

    public function test_missing_throws_not_found(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->expectException(AppException::class);
        $bus->execute(new SetInquiryCommentCommand(Uuid::v4()->toRfc4122(), 'заметка'));
    }

    public function test_rejects_non_manager(): void
    {
        self::bootKernel();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->expectException(ForbiddenException::class);
        $bus->execute(new SetInquiryCommentCommand(Uuid::v4()->toRfc4122(), 'заметка'));
    }
}
