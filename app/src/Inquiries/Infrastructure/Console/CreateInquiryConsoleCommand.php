<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Console;

use App\Inquiries\Application\UseCase\Command\CreateInquiry\CreateInquiryCommand;
use App\Inquiries\Application\UseCase\Command\CreateInquiry\CreateInquiryCommandResult;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\File\FileStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Боевой путь создания запроса из консоли: кладёт файл (если задан) в Shared/File (stage) и
 * диспатчит CreateInquiryCommand — запись ложится в БД (Received) и раздаётся событие
 * InquirySubmitted в async. Саму обработку делает воркер (messenger:consume async). Актор — система
 * (ConsoleAuthenticationSubscriber ставит SystemUser, проходит capability-гейт).
 */
#[AsCommand(
    name: 'app:inquiry:create',
    description: 'Боевой путь: создать запрос из текста/файла (пишет в БД + async), обработку выполнит воркер',
)]
final class CreateInquiryConsoleCommand extends Command
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly FileStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('text', InputArgument::OPTIONAL, 'Текст запроса (если без --file)')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Путь к файлу В КОНТЕЙНЕРЕ — кладётся в Shared/File');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $fileIds = [];
        $file = $input->getOption('file');
        if (is_string($file) && '' !== $file) {
            if (!is_file($file)) {
                $io->error('Файл не найден в контейнере: '.$file);

                return Command::INVALID;
            }
            $stored = $this->storage->stage('system', new UploadedFile($file, basename($file), null, null, true));
            $fileIds[] = $stored->id();
            $io->writeln(sprintf('Вложение в Shared/File: %s (%s)', $stored->id(), $stored->originalName()));
        }

        $text = $input->getArgument('text');
        $rawText = is_string($text) ? $text : '';
        if ('' === trim($rawText) && [] === $fileIds) {
            $io->error('Нужен текст аргументом или --file.');

            return Command::INVALID;
        }

        $result = $this->commandBus->execute(new CreateInquiryCommand(InquiryType::SystemSelection, $rawText, $fileIds));
        \assert($result instanceof CreateInquiryCommandResult);

        $io->success(sprintf('Запрос создан: %s (статус Received). Обработку запустит воркер: ./run console messenger:consume async --limit=1 -vv', $result->id));

        return Command::SUCCESS;
    }
}
