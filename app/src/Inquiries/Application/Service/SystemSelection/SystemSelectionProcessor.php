<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\SystemSelection;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Inquiries\Application\DTO\InquiryText;
use App\Inquiries\Application\Exception\EmptyInquiryTextException;
use App\Inquiries\Application\Exception\InquiryProcessingException;
use App\Inquiries\Application\Exception\ParameterExtractionFailedException;
use App\Inquiries\Application\Port\ApprovedParameterCache;
use App\Inquiries\Application\Port\InquiryParametersExtractor;
use App\Inquiries\Application\Port\InquiryTextAssembler;
use App\Inquiries\Application\Service\InquiryProcessor;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;

/**
 * Движок подбора систем. Только ОРКЕСТРАЦИЯ: текст собирает ассемблер, параметры даёт кэш ИЛИ
 * модель+маппер, поиск+снимок — общий SystemSelectionSearch (тот же путь, что у ручного переподбора).
 * Гейты: пустой текст → не зовём модель; пустые параметры / ошибка поиска → типизированные ошибки
 * из search. Мутирует агрегат; сохранение/отказ — на диспетчере.
 */
final readonly class SystemSelectionProcessor implements InquiryProcessor
{
    public function __construct(
        private InquiryTextAssembler $assembler,
        private ApprovedParameterCache $cache,
        private InquiryParametersExtractor $extractor,
        private SystemSelectionParameterMapper $mapper,
        private SystemSelectionSearch $search,
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

        // Гейт 1: сборка дала пустоту (тела нет, вложения не прочитались) — модель не зовём.
        if ('' === trim($assembled->text)) {
            $inquiry->noteWarnings(...$warnings);

            throw new EmptyInquiryTextException();
        }

        $hit = $this->cache->lookup(InquiryType::SystemSelection, $assembled->text);
        if (null !== $hit) {
            $parameters = $hit->parameters;
            $source = new ParameterSource(ParameterStrategy::Cache, array_filter(['sourceInquiryId' => $hit->sourceInquiryId]));
        } else {
            try {
                $extraction = $this->extractor->extract(new InquiryText($assembled->text));
            } catch (\Throwable $e) {
                $inquiry->noteWarnings(...$warnings);

                throw new ParameterExtractionFailedException($e);
            }
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

        try {
            $answer = $this->search->search(CoatingSystemsFilterInput::fromArray($parameters->toArray()));
        } catch (InquiryProcessingException $e) {
            // Пустые параметры / сбой поиска — сохранить накопленные предупреждения перед отказом.
            $inquiry->noteWarnings(...$warnings);

            throw $e;
        }

        $inquiry->match($answer, $now);
        if (0 === $answer->total) {
            $warnings[] = 'Подбор не дал ни одной системы — проверьте параметры.';
        }
        $inquiry->noteWarnings(...$warnings);
    }
}
