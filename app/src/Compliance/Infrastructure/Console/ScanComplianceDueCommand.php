<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Console;

use App\Compliance\Application\Service\DueSoonScanner;
use App\Compliance\Domain\Type\ComplianceBucket;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ручной прогон прохода сроков (тот же сервис, что гоняет Scheduler ежедневно): публикует дайджесты по людям.
 * Без флагов — все состояния рассылки ({@see DueSoonScanner::NOTIFIABLE_BUCKETS}). Булев флаг на бакет
 * (--overdue/--soon/--missing — набор следует из того же списка) сужает ЭТОТ прогон до выбранного подмножества.
 */
#[AsCommand(name: 'app:compliance:scan-due', description: 'Проход сроков обязанностей: дайджест «подходит срок/просрочено/не выполнено» по людям.')]
final class ScanComplianceDueCommand extends Command
{
    public function __construct(private readonly DueSoonScanner $scanner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        // Флаг на каждое уведомляемое состояние — набор берётся из единого списка политики, не дублируется.
        foreach (DueSoonScanner::NOTIFIABLE_BUCKETS as $bucket) {
            $this->addOption($bucket->value, null, InputOption::VALUE_NONE, sprintf('Включить в прогон только состояние «%s»', $bucket->value));
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selected = [];
        foreach (DueSoonScanner::NOTIFIABLE_BUCKETS as $bucket) {
            if (true === $input->getOption($bucket->value)) {
                $selected[] = $bucket;
            }
        }
        $onlyBuckets = [] === $selected ? null : $selected;

        $sent = $this->scanner->scan(new \DateTimeImmutable(), $onlyBuckets);

        $scope = null === $onlyBuckets
            ? 'все состояния'
            : implode(', ', array_map(static fn (ComplianceBucket $b): string => $b->value, $onlyBuckets));
        $output->writeln(sprintf('Проход завершён (%s). Дайджестов отправлено: %d (доставка — асинхронно, нужен messenger-воркер).', $scope, $sent));

        return Command::SUCCESS;
    }
}
