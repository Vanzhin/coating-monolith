<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Application\UseCase\Command\ReprocessInquiry;

use App\Inquiries\Application\UseCase\Command\ReprocessInquiry\ReprocessInquiryCommand;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
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

final class ReprocessInquiryCommandHandlerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function test_reprocess_sets_manual_params_and_matches(): void
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

        // запрос упал — инженер вбивает параметры руками
        $inquiry = $factory->create(InquiryType::SystemSelection, 'owner', 'текст', new StringCollection(), new \DateTimeImmutable());
        $inquiry->fail('не распознал', new \DateTimeImmutable());
        $repo->add($inquiry);
        $id = $inquiry->getId();

        $bus->execute(new ReprocessInquiryCommand($id, ['environment' => 'immersion', 'substrates' => ['steel_carbon']]));
        $em->clear();

        $loaded = $repo->findOneById($id);
        self::assertNotNull($loaded);
        self::assertSame(InquiryStatus::Recognized, $loaded->getStatus());
        self::assertSame(ParameterStrategy::Manual, $loaded->getParameterSource()?->strategy);
        self::assertSame('immersion', $loaded->getRecognizedParameters()?->toArray()['environment'] ?? null);
        self::assertNull($loaded->getFailureReason());
    }

    public function test_empty_parameters_rejected(): void
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

        $inquiry = $factory->create(InquiryType::SystemSelection, 'owner', 'текст', new StringCollection(), new \DateTimeImmutable());
        $repo->add($inquiry);

        $this->expectException(AppException::class);
        $bus->execute(new ReprocessInquiryCommand($inquiry->getId(), ['substrates' => [], 'environment' => null]));
    }

    public function test_reprocess_missing_throws_not_found(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->expectException(AppException::class);
        $bus->execute(new ReprocessInquiryCommand(Uuid::v4()->toRfc4122(), ['environment' => 'immersion']));
    }

    public function test_rejects_non_manager(): void
    {
        self::bootKernel();
        $bus = static::getContainer()->get(CommandBusInterface::class);
        \assert($bus instanceof CommandBusInterface);

        $this->expectException(ForbiddenException::class);
        $bus->execute(new ReprocessInquiryCommand(Uuid::v4()->toRfc4122(), ['environment' => 'immersion']));
    }
}
