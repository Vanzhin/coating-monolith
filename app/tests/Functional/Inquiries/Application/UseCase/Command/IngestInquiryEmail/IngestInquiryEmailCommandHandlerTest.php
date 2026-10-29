<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Application\UseCase\Command\IngestInquiryEmail;

use App\Inquiries\Application\UseCase\Command\IngestInquiryEmail\IngestInquiryEmailCommand;
use App\Inquiries\Application\UseCase\Command\IngestInquiryEmail\IngestInquiryEmailResult;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IngestInquiryEmailCommandHandlerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function test_creates_inquiry_owned_by_resolved_sender(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = static::getContainer();
        $bus = $c->get(CommandBusInterface::class);
        $repo = $c->get(InquiryRepositoryInterface::class);
        \assert($bus instanceof CommandBusInterface);
        \assert($repo instanceof InquiryRepositoryInterface);

        $email = 'sender_'.uniqid('', true).'@example.com';
        $user = $this->createUser($c, $email);

        $result = $bus->execute(new IngestInquiryEmailCommand($email, 'Подбор покрытия', 'нужна система для этанола, погружение'));
        \assert($result instanceof IngestInquiryEmailResult);

        self::assertFalse($result->skipped);
        self::assertNotNull($result->inquiryId);
        $inquiry = $repo->findOneById($result->inquiryId);
        self::assertNotNull($inquiry);
        self::assertSame(InquiryStatus::Received, $inquiry->getStatus());
        self::assertSame($user->getUlid(), $inquiry->getOwnerId());
        self::assertStringContainsString('Подбор покрытия', $inquiry->getRawText());
        self::assertStringContainsString('этанол', $inquiry->getRawText());
    }

    public function test_unknown_sender_is_not_created(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $result = $bus->execute(new IngestInquiryEmailCommand('nobody_'.uniqid('', true).'@example.com', 'тема', 'тело'));
        \assert($result instanceof IngestInquiryEmailResult);

        self::assertTrue($result->skipped);
        self::assertNull($result->inquiryId);
    }

    public function test_rejects_non_manager(): void
    {
        self::bootKernel();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->expectException(ForbiddenException::class);
        $bus->execute(new IngestInquiryEmailCommand('x@example.com', 'тема', 'тело'));
    }

    private function createUser(object $container, string $email): User
    {
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $user = new User(new Email($email));
        $user->setPassword('pw', $container->get(UserPasswordHasherInterface::class));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
