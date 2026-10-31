<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Application\UseCase\Query\GetExtractionAccuracy;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Inquiries\Application\DTO\View\ExtractionAccuracyView;
use App\Inquiries\Application\UseCase\Query\GetExtractionAccuracy\GetExtractionAccuracyQuery;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Factory\InquiryFactory;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Tests\Support\AuthenticatesActorTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetExtractionAccuracyQueryTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function test_groups_thermal_keys_into_one_labeled_row_with_summed_counts(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $container = static::getContainer();
        $bus = $container->get(QueryBusInterface::class);
        $repo = $container->get(InquiryRepositoryInterface::class);
        $factory = $container->get(InquiryFactory::class);
        \assert($bus instanceof QueryBusInterface);
        \assert($repo instanceof InquiryRepositoryInterface);
        \assert($factory instanceof InquiryFactory);

        $now = new \DateTimeImmutable();
        $inquiry = $factory->create(InquiryType::SystemSelection, 'owner', 'печь, нагрев', new StringCollection(), $now);
        // робот: нагрев 100 °C, сухой; инженер правит температуру на 120 (среда и флаг пика — без изменений)
        $robot = new CoatingSystemsFilterInput(thermalTemperature: 100, thermalEnvironment: 'dry_heat');
        $inquiry->recognize(RecognizedParameters::recognized($robot->toArray()), new ParameterSource(ParameterStrategy::Model), $now);
        $human = new CoatingSystemsFilterInput(thermalTemperature: 120, thermalEnvironment: 'dry_heat');
        $inquiry->editParameters($human->toArray(), $now);
        $inquiry->approve('tester', $now);
        $repo->add($inquiry);

        $cachedInquiry = $factory->create(InquiryType::SystemSelection, 'owner', 'печь, нагрев', new StringCollection(), $now);
        $cachedInquiry->recognize(RecognizedParameters::recognized($robot->toArray()), new ParameterSource(ParameterStrategy::Cache), $now);
        $cachedInquiry->approve('tester', $now);
        $repo->add($cachedInquiry);

        $view = $bus->execute(new GetExtractionAccuracyQuery());
        \assert($view instanceof ExtractionAccuracyView);

        self::assertSame(1, $view->inquiriesScored);
        self::assertSame(2, $view->inquiriesApproved);
        // thermalTemperature/thermalEnvironment/thermalIncludingPeak схлопнуты в одну строку
        self::assertCount(1, $view->rows);
        $row = $view->rows[0];
        self::assertSame('Тепловая нагрузка', $row->label);
        self::assertSame(3, $row->total);     // temp + environment + includingPeak(false)
        self::assertSame(2, $row->agreed);    // environment и флаг пика совпали
        self::assertSame(1, $row->mismatch);  // температуру правили 100 → 120
        self::assertSame(0, $row->missed);
        self::assertSame(0, $row->extra);
    }
}
