<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Application\UseCase\Command\ApproveInquiry;

use App\Inquiries\Application\UseCase\Command\ApproveInquiry\ApproveInquiryCommand;
use App\Inquiries\Domain\Aggregate\Inquiry\AnswerSnapshot;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
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

final class ApproveInquiryCommandHandlerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function test_approves_matched_inquiry(): void
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

        $now = new \DateTimeImmutable();
        $inquiry = $factory->create(InquiryType::SystemSelection, 'owner', 'текст', new StringCollection(), $now);
        $inquiry->recognize(new RecognizedParameters(['search' => 'этанол']), new ParameterSource(ParameterStrategy::Model), $now);
        $inquiry->match(new AnswerSnapshot(1), $now);
        $repo->add($inquiry);
        $id = $inquiry->getId();

        $bus->execute(new ApproveInquiryCommand($id));
        $em->clear();

        $loaded = $repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertTrue($loaded->isApproved());
        self::assertSame('system', $loaded->getVerification()?->verifiedBy);
    }

    public function test_approve_missing_throws_not_found(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->expectException(AppException::class);
        $bus->execute(new ApproveInquiryCommand(Uuid::v4()->toRfc4122()));
    }

    public function test_rejects_non_manager(): void
    {
        self::bootKernel();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->expectException(ForbiddenException::class);
        $bus->execute(new ApproveInquiryCommand(Uuid::v4()->toRfc4122()));
    }

    public function test_approving_not_matched_inquiry_throws_clean_error(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $container = static::getContainer();
        $bus = $container->get(CommandBusInterface::class);
        $repo = $container->get(InquiryRepositoryInterface::class);
        $factory = $container->get(InquiryFactory::class);
        \assert($bus instanceof CommandBusInterface);
        \assert($repo instanceof InquiryRepositoryInterface);
        \assert($factory instanceof InquiryFactory);

        // запрос в статусе Received (подбор не выполнен) — одобрять нечего
        $inquiry = $factory->create(InquiryType::SystemSelection, 'owner', 'текст', new StringCollection(), new \DateTimeImmutable());
        $repo->add($inquiry);

        $this->expectException(AppException::class);
        $bus->execute(new ApproveInquiryCommand($inquiry->getId()));
    }
}
