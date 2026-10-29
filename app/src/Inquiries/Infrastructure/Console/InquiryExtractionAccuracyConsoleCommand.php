<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Console;

use App\Inquiries\Application\DTO\View\ExtractionAccuracyView;
use App\Inquiries\Application\UseCase\Query\GetExtractionAccuracy\GetExtractionAccuracyQuery;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Repository\InquiriesFilter;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Градусник точности извлечения в консоли: таблица точности по осям (через GetExtractionAccuracyQuery —
 * единый источник с веб-дашбордом: тот же эталон, скоринг и группировка по подписям) + опц. дамп правок
 * робот→человек по запросам. Модель НЕ зовёт, read-only. On-demand, не для CI. Актор — система (CLI).
 */
#[AsCommand(
    name: 'app:inquiry:extraction-accuracy',
    description: 'Точность извлечения по осям на эталоне одобренных запросов (без вызова модели)',
)]
final class InquiryExtractionAccuracyConsoleCommand extends Command
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly InquiryRepositoryInterface $inquiries,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Сколько одобренных запросов учесть', '500')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Дамп пар робот→человек по правленым осям, с id запроса');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));

        /** @var ExtractionAccuracyView $view */
        $view = $this->queryBus->execute(new GetExtractionAccuracyQuery($limit));

        if ([] === $view->rows) {
            $io->warning('Одобренных запросов с параметрами нет — эталон пуст.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Точность извлечения по осям (эталон: %d одобренных)', $view->inquiriesScored));
        $io->table(
            ['Ось', 'Всего', 'Совпало', 'Пропустила', 'Лишнее', 'Разошлись', 'Точность'],
            array_map(static fn ($r) => [
                $r->label,
                $r->total,
                $r->agreed,
                $r->missed,
                $r->extra,
                $r->mismatch,
                sprintf('%d%%', (int) round($r->accuracy() * 100)),
            ], $view->rows),
        );

        if ($input->getOption('json')) {
            $io->section('Правки инженера (робот → человек)');
            $output->writeln((string) json_encode($this->drill($limit), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return Command::SUCCESS;
    }

    /**
     * Per-inquiry срез правок для разбора — у запроса есть id (в дашборд-view его нет).
     *
     * @return array<string, array<string, array{robot: mixed, human: mixed}>>
     */
    private function drill(int $limit): array
    {
        $page = $this->inquiries->findByFilter(new InquiriesFilter(
            pager: Pager::fromPage(1, $limit),
            status: InquiryStatus::Approved,
        ));

        $drill = [];
        foreach ($page->items as $inquiry) {
            \assert($inquiry instanceof Inquiry);
            $corrections = $inquiry->getRecognizedParameters()?->corrections() ?? [];
            if ([] !== $corrections) {
                $drill[$inquiry->getId()] = $corrections;
            }
        }

        return $drill;
    }
}
