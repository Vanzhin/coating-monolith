<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Console;

use App\Inquiries\Application\Service\InquiryMailboxPoller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ручной прогон опроса ящика подбора (тот же проход, что у Scheduler). Тонкая: зовёт InquiryMailboxPoller,
 * печатает итог. Актор — система (CLI). Для боевого периодического приёма работает Scheduler-расписание.
 */
#[AsCommand(
    name: 'app:inquiry:poll-mail',
    description: 'Вычитать непрочитанные письма ящика подбора и завести из них запросы',
)]
final class PollInquiryMailboxConsoleCommand extends Command
{
    public function __construct(private readonly InquiryMailboxPoller $poller)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Сколько писем вычитать за проход', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $summary = $this->poller->poll((int) $input->getOption('limit'));

        if (!$summary['configured']) {
            $io->warning('Почтовый ящик подбора не настроен (INQUIRY_IMAP_*).');

            return Command::SUCCESS;
        }

        $io->success(sprintf('Писем: %d, заведено запросов: %d, пропущено: %d', $summary['processed'], $summary['created'], $summary['skipped']));

        return Command::SUCCESS;
    }
}
