<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Console;

use App\Compliance\Application\Service\DueSoonScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ручной прогон прохода сроков (тот же сервис, что гоняет Scheduler ежедневно). `--prime` — засеять маркеры
 * текущим состоянием БЕЗ рассылки: разовый прогон сразу после деплоя, чтобы первый боевой скан не затопил
 * уже существующими сроками (уведомляем только на последующих ухудшениях).
 */
#[AsCommand(name: 'app:compliance:scan-due', description: 'Проход сроков обязанностей: уведомления по ухудшению состояния.')]
final class ScanComplianceDueCommand extends Command
{
    public function __construct(private readonly DueSoonScanner $scanner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('prime', null, InputOption::VALUE_NONE, 'Засеять маркеры без рассылки (после деплоя)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prime = (bool) $input->getOption('prime');
        $sent = $this->scanner->scan(new \DateTimeImmutable(), !$prime);

        $output->writeln($prime
            ? 'Маркеры засеяны (прайм), рассылки не было.'
            : sprintf('Проход завершён. Дайджестов отправлено: %d (доставка — асинхронно, нужен messenger-воркер).', $sent));

        return Command::SUCCESS;
    }
}
