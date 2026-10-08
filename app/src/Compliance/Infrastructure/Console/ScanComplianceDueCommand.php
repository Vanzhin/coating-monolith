<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Console;

use App\Compliance\Application\Service\DueSoonScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** Ручной прогон прохода сроков (тот же сервис, что гоняет Scheduler ежедневно): публикует дайджесты по людям. */
#[AsCommand(name: 'app:compliance:scan-due', description: 'Проход сроков обязанностей: дайджест «подходит срок/просрочено» по людям.')]
final class ScanComplianceDueCommand extends Command
{
    public function __construct(private readonly DueSoonScanner $scanner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sent = $this->scanner->scan(new \DateTimeImmutable());
        $output->writeln(sprintf('Проход завершён. Дайджестов отправлено: %d (доставка — асинхронно, нужен messenger-воркер).', $sent));

        return Command::SUCCESS;
    }
}
