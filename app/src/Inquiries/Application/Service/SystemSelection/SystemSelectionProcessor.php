<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\SystemSelection;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemDTO;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQuery;
use App\Coatings\Application\UseCase\Query\SearchCoatingSystems\SearchCoatingSystemsQueryResult;
use App\Inquiries\Application\DTO\InquiryText;
use App\Inquiries\Application\Port\ApprovedParameterCache;
use App\Inquiries\Application\Port\InquiryParametersExtractor;
use App\Inquiries\Application\Port\InquiryTextAssembler;
use App\Inquiries\Application\Service\InquiryProcessor;
use App\Inquiries\Domain\Aggregate\Inquiry\AnswerSnapshot;
use App\Inquiries\Domain\Aggregate\Inquiry\FoundSystem;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Shared\Application\Query\QueryBusInterface;

/**
 * Движок подбора систем. Только ОРКЕСТРАЦИЯ — ни HTTP, ни файлов, ни отпечатков, ни домена Coatings:
 * текст собирает ассемблер, параметры даёт кэш ИЛИ модель+маппер, поиск — query-шина Coatings. Одна
 * развилка: попали в кэш (модель молчит, strategy=Cache) или промах (модель, strategy=Model). Ответ
 * всегда свежий — ре-прогон поиска по параметрам. Мутирует агрегат; сохранение/отказ — на диспетчере.
 */
final readonly class SystemSelectionProcessor implements InquiryProcessor
{
    public function __construct(
        private InquiryTextAssembler $assembler,
        private ApprovedParameterCache $cache,
        private InquiryParametersExtractor $extractor,
        private SystemSelectionParameterMapper $mapper,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function supportedType(): InquiryType
    {
        return InquiryType::SystemSelection;
    }

    public function process(Inquiry $inquiry): void
    {
        $now = new \DateTimeImmutable();

        $assembled = $this->assembler->assemble($inquiry);
        $warnings = $assembled->warnings;

        $hit = $this->cache->lookup(InquiryType::SystemSelection, $assembled->text);
        if (null !== $hit) {
            $parameters = $hit->parameters;
            $source = new ParameterSource(ParameterStrategy::Cache, array_filter(['sourceInquiryId' => $hit->sourceInquiryId]));
        } else {
            $extraction = $this->extractor->extract(new InquiryText($assembled->text));
            $mapped = $this->mapper->map($extraction->parameters);
            $warnings = [...$warnings, ...$mapped->warnings];
            if ([] !== $extraction->droppedValues) {
                $warnings[] = 'Отброшены значения вне справочника: '.implode(', ', $extraction->droppedValues);
            }
            $parameters = new RecognizedParameters($mapped->input->toArray());
            $source = new ParameterSource(ParameterStrategy::Model, [
                'extractor' => $extraction->metadata->strategy,
                'model' => $extraction->metadata->model,
                'promptVersion' => $extraction->metadata->promptVersion,
                'tokensIn' => $extraction->metadata->tokensIn,
                'tokensOut' => $extraction->metadata->tokensOut,
                'durationMs' => $extraction->metadata->durationMs,
            ]);
        }

        $inquiry->recognize($parameters, $source, $now);

        $searchResult = $this->queryBus->execute(
            SearchCoatingSystemsQuery::fromParameters(CoatingSystemsFilterInput::fromArray($parameters->toArray())),
        );
        \assert($searchResult instanceof SearchCoatingSystemsQueryResult);

        $systems = array_map(
            static fn (CoatingSystemDTO $dto): FoundSystem => new FoundSystem($dto->id, $dto->title),
            $searchResult->items,
        );
        $inquiry->match(new AnswerSnapshot($searchResult->total, ...$systems), $now);

        if (0 === $searchResult->total) {
            $warnings[] = 'Подбор не дал ни одной системы — проверьте параметры.';
        }
        $inquiry->noteWarnings(...$warnings);
    }
}
