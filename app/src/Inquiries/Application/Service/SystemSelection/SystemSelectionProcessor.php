<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service\SystemSelection;

use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Inquiries\Application\DTO\InquiryText;
use App\Inquiries\Application\Exception\EmptyInquiryTextException;
use App\Inquiries\Application\Exception\ParameterExtractionFailedException;
use App\Inquiries\Application\Exception\ParametersNotRecognizedException;
use App\Inquiries\Application\Port\ApprovedParameterCache;
use App\Inquiries\Application\Port\InquiryParametersExtractor;
use App\Inquiries\Application\Port\InquiryTextAssembler;
use App\Inquiries\Application\Service\InquiryProcessor;
use App\Inquiries\Application\Service\TemperatureGroundingChecker;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;

/**
 * Движок РАСПОЗНАВАНИЯ параметров подбора. Только оркестрация: текст собирает ассемблер, параметры даёт
 * кэш ИЛИ модель+маппер. Поиск систем тут НЕ гоняем — подобранные системы это живой результат прогона
 * распознанных параметров по каталогу (считается на показе карточки), не состояние запроса. Гейты:
 * пустой текст → не зовём модель; ошибка модели / пустые параметры (ни одной оси) → типизированные
 * ошибки. Мутирует агрегат (recognize); сохранение/отказ — на диспетчере.
 */
final readonly class SystemSelectionProcessor implements InquiryProcessor
{
    public function __construct(
        private InquiryTextAssembler $assembler,
        private ApprovedParameterCache $cache,
        private InquiryParametersExtractor $extractor,
        private SystemSelectionParameterMapper $mapper,
        private TemperatureGroundingChecker $grounding,
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
            $grounding = $this->grounding->check($extraction->parameters, $assembled->text);
            $warnings = [...$warnings, ...$mapped->warnings, ...$extraction->conflicts, ...$grounding];
            if ([] !== $extraction->droppedValues) {
                $warnings[] = 'Отброшены значения вне справочника: '.implode(', ', $extraction->droppedValues);
            }
            $parameters = RecognizedParameters::recognized($mapped->input->toArray());
            $source = new ParameterSource(ParameterStrategy::Model, [
                'extractor' => $extraction->metadata->strategy,
                'model' => $extraction->metadata->model,
                'promptVersion' => $extraction->metadata->promptVersion,
                'tokensIn' => $extraction->metadata->tokensIn,
                'tokensOut' => $extraction->metadata->tokensOut,
                'durationMs' => $extraction->metadata->durationMs,
            ]);
        }

        // Гейт 2: распознали ли хоть одну ось фильтра? Пусто → подбор-по-всему вернул бы весь каталог —
        // это не распознавание, а провал (инженер вбивает параметры вручную).
        if (!CoatingSystemsFilterInput::fromArray($parameters->toArray())->hasAnyConstraint()) {
            $inquiry->noteWarnings(...$warnings);

            throw new ParametersNotRecognizedException();
        }

        $inquiry->recognize($parameters, $source, $now);
        $inquiry->noteWarnings(...$warnings);
    }
}
