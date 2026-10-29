<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetExtractionAccuracy;

use App\Coatings\Application\UseCase\Query\GetCoatingSystemsFilterAxisLabels\GetCoatingSystemsFilterAxisLabelsQuery;
use App\Inquiries\Application\DTO\View\AxisAccuracyRow;
use App\Inquiries\Application\DTO\View\ExtractionAccuracyView;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Application\Service\Eval\AxisAccuracy;
use App\Inquiries\Application\Service\Eval\ExtractionEvalScorer;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Repository\InquiriesFilter;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Точность извлечения по осям на эталоне одобренных запросов (только админ). Читает сохранённого
 * робота против одобренного значения — модель НЕ зовёт. Подписи осей берёт из единого словаря
 * Coatings (через шину), оси с общей подписью (тепловая нагрузка, диапазоны) схлопывает в одну строку.
 */
final readonly class GetExtractionAccuracyQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
        private ExtractionEvalScorer $scorer,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetExtractionAccuracyQuery $query): ExtractionAccuracyView
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $page = $this->repository->findByFilter(new InquiriesFilter(
            pager: Pager::fromPage(1, max(1, $query->limit)),
            status: InquiryStatus::Approved,
        ));

        $params = [];
        foreach ($page->items as $inquiry) {
            \assert($inquiry instanceof Inquiry);
            if (ParameterStrategy::Cache === $inquiry->getParameterSource()?->strategy) {
                continue; // кэш-копия чужих параметров — модель тут не работала, в выборку точности не берём (задвоила бы близнеца)
            }
            $recognized = $inquiry->getRecognizedParameters();
            if ($recognized instanceof RecognizedParameters) {
                $params[] = $recognized;
            }
        }

        $report = $this->scorer->score($params);

        /** @var array<string, string> $labels */
        $labels = $this->queryBus->execute(new GetCoatingSystemsFilterAxisLabelsQuery());

        return new ExtractionAccuracyView($this->groupByLabel($report->axes, $labels), $report->inquiriesScored);
    }

    /**
     * Схлопывает оси с общей подписью в одну строку (суммой счётчиков). Слабые — сверху.
     *
     * @param list<AxisAccuracy>    $axes
     * @param array<string, string> $labels
     *
     * @return list<AxisAccuracyRow>
     */
    private function groupByLabel(array $axes, array $labels): array
    {
        /** @var array<string, array{total: int, agreed: int, missed: int, extra: int, mismatch: int}> $grouped */
        $grouped = [];
        foreach ($axes as $a) {
            $label = $labels[$a->axis] ?? $a->axis;
            $grouped[$label] ??= ['total' => 0, 'agreed' => 0, 'missed' => 0, 'extra' => 0, 'mismatch' => 0];
            $grouped[$label]['total'] += $a->total;
            $grouped[$label]['agreed'] += $a->agreed;
            $grouped[$label]['missed'] += $a->missed;
            $grouped[$label]['extra'] += $a->extra;
            $grouped[$label]['mismatch'] += $a->mismatch;
        }

        $rows = [];
        foreach ($grouped as $label => $c) {
            $rows[] = new AxisAccuracyRow($label, $c['total'], $c['agreed'], $c['missed'], $c['extra'], $c['mismatch']);
        }
        usort($rows, static fn (AxisAccuracyRow $a, AxisAccuracyRow $b) => $a->accuracy() <=> $b->accuracy());

        return $rows;
    }
}
