<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Infrastructure\Repository;

use App\Inquiries\Domain\Aggregate\Inquiry\AnswerSnapshot;
use App\Inquiries\Domain\Aggregate\Inquiry\FoundSystem;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Factory\InquiryFactory;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InquiryRepositoryTest extends KernelTestCase
{
    public function test_persists_and_reloads_full_aggregate(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $repo = $container->get(InquiryRepositoryInterface::class);
        $factory = $container->get(InquiryFactory::class);
        $em = $container->get(EntityManagerInterface::class);
        \assert($repo instanceof InquiryRepositoryInterface);
        \assert($factory instanceof InquiryFactory);
        \assert($em instanceof EntityManagerInterface);

        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $inquiry = $factory->create(
            InquiryType::SystemSelection,
            'owner-ulid-0001',
            'нужна система для этанола, погружение',
            new StringCollection('file-1', 'file-2'),
            $now,
        );
        $inquiry->recognize(
            new RecognizedParameters(['environment' => 'immersion', 'substanceIds' => ['s1'], 'thermalTemperature' => 40]),
            new ParameterSource(ParameterStrategy::Model, ['model' => 'qwen', 'tokensIn' => 1200]),
            $now,
        );
        $inquiry->match(new AnswerSnapshot(2, new FoundSystem('id1', 'Система 1'), new FoundSystem('id2', 'Система 2')), $now);
        $inquiry->noteWarnings('вещество «уайт-спирит» не в реестре — в подборе не учтено');
        $inquiry->approve('engineer-ulid-9', $now);

        $id = $inquiry->getId();
        $repo->add($inquiry);
        $em->clear();

        $loaded = $repo->findOneById($id);

        self::assertNotNull($loaded);
        self::assertSame($id, $loaded->getId());
        self::assertSame(InquiryType::SystemSelection, $loaded->getType());
        self::assertSame('owner-ulid-0001', $loaded->getOwnerId());
        self::assertSame('нужна система для этанола, погружение', $loaded->getRawText());
        self::assertSame(['file-1', 'file-2'], $loaded->getAttachmentFileIds()->getList());
        self::assertSame(InquiryStatus::Approved, $loaded->getStatus());
        self::assertTrue($loaded->isApproved());

        $params = $loaded->getRecognizedParameters();
        self::assertNotNull($params);
        self::assertSame(['environment' => 'immersion', 'substanceIds' => ['s1'], 'thermalTemperature' => 40], $params->toArray());

        $source = $loaded->getParameterSource();
        self::assertNotNull($source);
        self::assertSame(ParameterStrategy::Model, $source->strategy);
        self::assertSame('qwen', $source->details['model'] ?? null);

        $answer = $loaded->getAnswer();
        self::assertNotNull($answer);
        self::assertSame(2, $answer->total);
        self::assertCount(2, $answer->systems());
        self::assertSame('id1', $answer->systems()[0]->id);

        $verification = $loaded->getVerification();
        self::assertNotNull($verification);
        self::assertSame('engineer-ulid-9', $verification->verifiedBy);

        self::assertSame(['вещество «уайт-спирит» не в реестре — в подборе не учтено'], $loaded->getWarnings());
    }

    public function test_find_by_invalid_id_returns_null(): void
    {
        self::bootKernel();
        $repo = static::getContainer()->get(InquiryRepositoryInterface::class);
        \assert($repo instanceof InquiryRepositoryInterface);

        self::assertNull($repo->findOneById('not-a-uuid'));
    }
}
