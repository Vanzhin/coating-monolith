<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Console;

use App\Inquiries\Application\Service\Eval\ExtractionEvalScorer;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Repository\InquiriesFilter;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Градусник точности извлечения: прогоняет эталон (одобренные запросы) и печатает таблицу точности
 * по осям — где робот совпал с тем, что одобрил инженер, а где промахнулся. Модель НЕ зовёт
 * (читает сохранённого робота против эффективного значения), read-only. On-demand, не для CI.
 */
#[AsCommand(
    name: 'app:inquiry:extraction-accuracy',
    description: 'Точность извлечения по осям на эталоне одобренных запросов (без вызова модели)',
)]
final class InquiryExtractionAccuracyConsoleCommand extends Command
{
    public function __construct(
        private readonly InquiryRepositoryInterface $inquiries,
        private readonly ExtractionEvalScorer $scorer,
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

        $result = $this->inquiries->findByFilter(new InquiriesFilter(
            pager: Pager::fromPage(1, $limit),
            status: InquiryStatus::Approved,
        ));

        /** @var list<Inquiry> $approved */
        $approved = array_values(array_filter(
            $result->items,
            static fn (object $i): bool => $i instanceof Inquiry && null !== $i->getRecognizedParameters(),
        ));

        if ([] === $approved) {
            $io->warning('Одобренных запросов с параметрами нет — эталон пуст.');

            return Command::SUCCESS;
        }

        if ($result->total > count($result->items)) {
            $io->note(sprintf('Учтено %d из %d одобренных (лимит %d). Поднимите --limit, чтобы охватить все.', count($result->items), $result->total, $limit));
        }

        $report = $this->scorer->score(array_map(static fn (Inquiry $i) => $i->getRecognizedParameters(), $approved));

        $io->title(sprintf('Точность извлечения по осям (эталон: %d одобренных)', $report->inquiriesScored));
        $io->table(
            ['Ось', 'Всего', 'Совпало', 'Пропустила', 'Лишнее', 'Разошлись', 'Точность'],
            array_map(static fn ($a) => [
                $a->axis,
                $a->total,
                $a->agreed,
                $a->missed,
                $a->extra,
                $a->mismatch,
                sprintf('%d%%', (int) round($a->accuracy() * 100)),
            ], $report->axes),
        );

        if ($input->getOption('json')) {
            $drill = [];
            foreach ($approved as $inquiry) {
                $corrections = $inquiry->getRecognizedParameters()?->corrections() ?? [];
                if ([] !== $corrections) {
                    $drill[$inquiry->getId()] = $corrections;
                }
            }
            $io->section('Правки инженера (робот → человек)');
            $output->writeln((string) json_encode($drill, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return Command::SUCCESS;
    }
}
