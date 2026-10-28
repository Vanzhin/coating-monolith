<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Application\Service\SystemSelection;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Inquiries\Application\DTO\AssembledText;
use App\Inquiries\Application\DTO\CacheHit;
use App\Inquiries\Application\DTO\ChemicalResistanceParams;
use App\Inquiries\Application\DTO\ExtractedParameters;
use App\Inquiries\Application\DTO\ExtractionMetadata;
use App\Inquiries\Application\DTO\ExtractionResult;
use App\Inquiries\Application\DTO\InquiryText;
use App\Inquiries\Application\DTO\IntRangeDto;
use App\Inquiries\Application\DTO\ThermalParams;
use App\Inquiries\Application\Exception\CoatingSearchFailedException;
use App\Inquiries\Application\Exception\EmptyInquiryTextException;
use App\Inquiries\Application\Exception\ParameterExtractionFailedException;
use App\Inquiries\Application\Exception\ParametersNotRecognizedException;
use App\Inquiries\Application\Port\ApprovedParameterCache;
use App\Inquiries\Application\Port\InquiryParametersExtractor;
use App\Inquiries\Application\Port\InquiryTextAssembler;
use App\Inquiries\Application\Port\SubstanceResolver;
use App\Inquiries\Application\Service\SystemSelection\SystemSelectionParameterMapper;
use App\Inquiries\Application\Service\SystemSelection\SystemSelectionProcessor;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class SystemSelectionProcessorTest extends TestCase
{
    public function test_cache_miss_runs_model_maps_and_matches(): void
    {
        $inquiry = $this->inquiry();
        $processor = $this->processor(hit: null, assemblerWarnings: ['Файл «спека.pdf» — скан'], total: 2);

        $processor->process($inquiry);

        self::assertSame(InquiryStatus::Matched, $inquiry->getStatus());

        $source = $inquiry->getParameterSource();
        self::assertNotNull($source);
        self::assertSame(ParameterStrategy::Model, $source->strategy);
        self::assertSame('qwen', $source->details['model'] ?? null);

        $params = $inquiry->getRecognizedParameters();
        self::assertNotNull($params);
        self::assertSame(['sub-eth'], $params->toArray()['substanceIds']);
        self::assertSame(120, $params->toArray()['thermalTemperature']); // пик 120 → лимит выживания

        $answer = $inquiry->getAnswer();
        self::assertNotNull($answer);
        self::assertSame(2, $answer->total);
        self::assertCount(2, $answer->systems());
        self::assertSame('sys-0', $answer->systems()[0]->id);

        $warnings = $inquiry->getWarnings();
        self::assertContains('Файл «спека.pdf» — скан', $warnings);
        self::assertTrue($this->anyContains($warnings, 'уайт-спирит'));
        self::assertTrue($this->anyContains($warnings, 'Интергард'));
        self::assertTrue($this->anyContains($warnings, 'Отброшены'));
    }

    public function test_cache_hit_skips_model_and_marks_cache(): void
    {
        $inquiry = $this->inquiry();
        $hit = new CacheHit(new RecognizedParameters(['search' => 'этанол', 'substanceIds' => ['sub-eth']]), 'src-123');
        $processor = $this->processor(hit: $hit, assemblerWarnings: [], total: 1, failIfExtractorCalled: true);

        $processor->process($inquiry);

        self::assertSame(InquiryStatus::Matched, $inquiry->getStatus());

        $source = $inquiry->getParameterSource();
        self::assertNotNull($source);
        self::assertSame(ParameterStrategy::Cache, $source->strategy);
        self::assertSame('src-123', $source->details['sourceInquiryId'] ?? null);

        self::assertSame(
            ['search' => 'этанол', 'substanceIds' => ['sub-eth']],
            $inquiry->getRecognizedParameters()?->toArray(),
        );
    }

    public function test_zero_results_adds_warning(): void
    {
        $inquiry = $this->inquiry();
        $processor = $this->processor(hit: null, assemblerWarnings: [], total: 0);

        $processor->process($inquiry);

        self::assertTrue($this->anyContains($inquiry->getWarnings(), 'не дал ни одной'));
    }

    public function test_empty_text_throws_typed_error(): void
    {
        $this->expectException(EmptyInquiryTextException::class);
        $this->processor(assemblerText: '  ')->process($this->inquiry());
    }

    public function test_extractor_failure_wraps_as_typed_error(): void
    {
        $this->expectException(ParameterExtractionFailedException::class);
        $this->processor(extractorError: true)->process($this->inquiry());
    }

    public function test_empty_parameters_throw_not_recognized(): void
    {
        $this->expectException(ParametersNotRecognizedException::class);
        $this->processor(emptyExtraction: true)->process($this->inquiry());
    }

    public function test_search_failure_wraps_as_typed_error(): void
    {
        $this->expectException(CoatingSearchFailedException::class);
        $this->processor(searchError: true)->process($this->inquiry());
    }

    private function inquiry(): Inquiry
    {
        return new Inquiry(
            Uuid::v4(),
            InquiryType::SystemSelection,
            'owner',
            'нужна система для этанола, погружение',
            new StringCollection(),
            new \DateTimeImmutable('2026-01-01 10:00:00'),
        );
    }

    /** @param list<string> $assemblerWarnings */
    private function processor(
        ?CacheHit $hit = null,
        array $assemblerWarnings = [],
        int $total = 2,
        bool $failIfExtractorCalled = false,
        string $assemblerText = 'собранный текст',
        bool $extractorError = false,
        bool $emptyExtraction = false,
        bool $searchError = false,
    ): SystemSelectionProcessor {
        $assembler = new class($assemblerWarnings, $assemblerText) implements InquiryTextAssembler {
            /** @param list<string> $warnings */
            public function __construct(private readonly array $warnings, private readonly string $text)
            {
            }

            public function assemble(Inquiry $inquiry): AssembledText
            {
                return new AssembledText($this->text, $this->warnings);
            }
        };

        $cache = new class($hit) implements ApprovedParameterCache {
            public function __construct(private readonly ?CacheHit $hit)
            {
            }

            public function lookup(InquiryType $type, string $text): ?CacheHit
            {
                return $this->hit;
            }

            public function index(Inquiry $inquiry): void
            {
            }
        };

        $extraction = $emptyExtraction ? $this->emptyExtraction() : $this->extraction();
        $extractor = new class($extraction, $failIfExtractorCalled, $extractorError) implements InquiryParametersExtractor {
            public function __construct(
                private readonly ExtractionResult $result,
                private readonly bool $fail,
                private readonly bool $error,
            ) {
            }

            public function extract(InquiryText $text): ExtractionResult
            {
                if ($this->fail) {
                    throw new \LogicException('модель не должна вызываться при попадании в кэш');
                }
                if ($this->error) {
                    throw new \RuntimeException('Idle timeout reached');
                }

                return $this->result;
            }
        };

        $resolver = new class implements SubstanceResolver {
            public function resolve(string $name): ?string
            {
                return 'этанол' === $name ? 'sub-eth' : null;
            }
        };

        $queryBus = new class($this->searchResult($total), $searchError) implements QueryBusInterface {
            public function __construct(private readonly SearchCoatingSystemsQueryResult $result, private readonly bool $error)
            {
            }

            public function execute(QueryInterface $query): mixed
            {
                if ($this->error) {
                    throw new \RuntimeException('поиск упал');
                }

                return $this->result;
            }
        };

        return new SystemSelectionProcessor($assembler, $cache, $extractor, new SystemSelectionParameterMapper($resolver), $queryBus);
    }

    private function emptyExtraction(): ExtractionResult
    {
        $params = new ExtractedParameters(
            substrates: [],
            environment: null,
            specifiedCoatings: [],
            search: null,
            chemicalResistance: new ChemicalResistanceParams([], null, null),
            thermal: new ThermalParams(null, null, null),
            standard: null,
            category: null,
            durability: null,
            applicationMinTemp: null,
            minApplicationTimeAt20: null,
        );

        return new ExtractionResult($params, '{}', new ExtractionMetadata('llm:netangels', 'qwen', 'v1', 10, 5, 400), []);
    }

    private function extraction(): ExtractionResult
    {
        $params = new ExtractedParameters(
            substrates: ['steel_carbon'],
            environment: 'immersion',
            specifiedCoatings: ['Интергард 475'],
            search: 'пропарка',
            chemicalResistance: new ChemicalResistanceParams(['этанол', 'уайт-спирит'], 40, 90),
            thermal: new ThermalParams(60, 120, 'dry_heat'),
            standard: 'iso12944',
            category: 'C5',
            durability: 'high',
            applicationMinTemp: new IntRangeDto(5, null),
            minApplicationTimeAt20: new IntRangeDto(120, null),
        );

        return new ExtractionResult($params, '{}', new ExtractionMetadata('llm:netangels', 'qwen', 'v1', 1200, 300, 2600), ['непонятное']);
    }

    private function searchResult(int $total): SearchCoatingSystemsQueryResult
    {
        $items = [];
        for ($i = 0; $i < $total; ++$i) {
            $dto = new CoatingSystemDTO();
            $dto->id = 'sys-'.$i;
            $dto->title = 'Система '.$i;
            $items[] = $dto;
        }

        return new SearchCoatingSystemsQueryResult($items, $total);
    }

    /** @param list<string> $warnings */
    private function anyContains(array $warnings, string $needle): bool
    {
        foreach ($warnings as $w) {
            if (str_contains($w, $needle)) {
                return true;
            }
        }

        return false;
    }
}
