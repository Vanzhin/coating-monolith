<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Infrastructure\Cache;

use App\Inquiries\Application\Port\ApprovedParameterCache;
use App\Inquiries\Application\Service\ContentFingerprinter;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Factory\InquiryFactory;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class HashApprovedParameterCacheTest extends KernelTestCase
{
    public function test_recognized_twin_is_copied_by_hash(): void
    {
        [$cache, $fp, $factory, $repo] = $this->boot();
        $text = 'нефть до 90, погружение '.uniqid('', true);

        $twin = $factory->create(InquiryType::SystemSelection, 'owner', 'письмо', new StringCollection(), new \DateTimeImmutable());
        $twin->recognize(RecognizedParameters::recognized(['environment' => 'immersion']), new ParameterSource(ParameterStrategy::Model), new \DateTimeImmutable());
        $twin->setContentHash($fp->hash($text));
        $repo->add($twin);

        $hit = $cache->lookup(InquiryType::SystemSelection, $text);

        self::assertNotNull($hit);
        self::assertSame(['environment' => 'immersion'], $hit->parameters->toArray());
        self::assertSame($twin->getId(), $hit->sourceInquiryId);
    }

    public function test_different_text_misses(): void
    {
        [$cache, $fp, $factory, $repo] = $this->boot();
        $twin = $factory->create(InquiryType::SystemSelection, 'owner', 'письмо', new StringCollection(), new \DateTimeImmutable());
        $twin->recognize(RecognizedParameters::recognized(['environment' => 'immersion']), new ParameterSource(ParameterStrategy::Model), new \DateTimeImmutable());
        $twin->setContentHash($fp->hash('текст А '.uniqid('', true)));
        $repo->add($twin);

        self::assertNull($cache->lookup(InquiryType::SystemSelection, 'совсем другой текст '.uniqid('', true)));
    }

    public function test_failed_twin_is_not_copied(): void
    {
        [$cache, $fp, $factory, $repo] = $this->boot();
        $text = 'провальный '.uniqid('', true);

        $failed = $factory->create(InquiryType::SystemSelection, 'owner', 'письмо', new StringCollection(), new \DateTimeImmutable());
        $failed->fail('таймаут', new \DateTimeImmutable());
        $failed->setContentHash($fp->hash($text));
        $repo->add($failed);

        self::assertNull($cache->lookup(InquiryType::SystemSelection, $text));
    }

    /** @return array{0: ApprovedParameterCache, 1: ContentFingerprinter, 2: InquiryFactory, 3: InquiryRepositoryInterface} */
    private function boot(): array
    {
        self::bootKernel();
        $c = self::getContainer();

        return [
            $c->get(ApprovedParameterCache::class),
            $c->get(ContentFingerprinter::class),
            $c->get(InquiryFactory::class),
            $c->get(InquiryRepositoryInterface::class),
        ];
    }
}
