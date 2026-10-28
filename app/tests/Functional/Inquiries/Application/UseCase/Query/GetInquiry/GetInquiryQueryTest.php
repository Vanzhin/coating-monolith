<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Application\UseCase\Query\GetInquiry;

use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Inquiries\Application\DTO\View\InquiryView;
use App\Inquiries\Application\UseCase\Query\GetInquiry\GetInquiryQuery;
use App\Inquiries\Domain\Aggregate\Inquiry\AnswerSnapshot;
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
use Symfony\Component\Uid\Uuid;

final class GetInquiryQueryTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function test_param_rows_are_human_readable(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $container = static::getContainer();
        $bus = $container->get(QueryBusInterface::class);
        $repo = $container->get(InquiryRepositoryInterface::class);
        $factory = $container->get(InquiryFactory::class);
        $substanceRepo = $container->get(SubstanceRepositoryInterface::class);
        $spec = $container->get(SubstanceSpecification::class);
        \assert($bus instanceof QueryBusInterface);
        \assert($repo instanceof InquiryRepositoryInterface);
        \assert($factory instanceof InquiryFactory);
        \assert($substanceRepo instanceof SubstanceRepositoryInterface);
        \assert($spec instanceof SubstanceSpecification);

        $substanceId = Uuid::v4();
        $substanceRepo->add(new Substance($substanceId, 'Сырая нефть-'.bin2hex(random_bytes(3)), null, new StringCollection(), $spec));

        $now = new \DateTimeImmutable();
        $inquiry = $factory->create(InquiryType::SystemSelection, 'owner', 'запрос', new StringCollection(), $now);
        $input = new CoatingSystemsFilterInput(
            substrates: ['steel_carbon'],
            environment: 'immersion',
            substanceIds: [$substanceId->toRfc4122()],
            substanceTemperature: 40,
        );
        $inquiry->recognize(new RecognizedParameters($input->toArray()), new ParameterSource(ParameterStrategy::Model), $now);
        $inquiry->match(new AnswerSnapshot(0), $now);
        $repo->add($inquiry);

        $view = $bus->execute(new GetInquiryQuery($inquiry->getId()));
        \assert($view instanceof InquiryView);

        $joined = implode(' | ', array_map(static fn ($r): string => $r->label.': '.$r->value, $view->paramRows));

        self::assertStringContainsString('Вещества: Сырая нефть-', $joined);
        self::assertStringContainsString('Подложка: Углеродистая сталь', $joined);
        self::assertStringContainsString('Среда:', $joined);
        self::assertStringContainsString('Температура у вещества: 40 °C', $joined);
    }
}
