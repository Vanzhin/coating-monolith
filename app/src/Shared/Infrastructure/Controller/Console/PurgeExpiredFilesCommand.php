<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Console;

use App\Shared\Domain\File\FileStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:file:purge-expired',
    description: 'Удаляет просроченные временные загрузки (tmp) из хранилища и реестра.',
)]
final class PurgeExpiredFilesCommand extends Command
{
    public function __construct(private readonly FileStorage $storage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->storage->purgeExpired();
        (new SymfonyStyle($input, $output))->success(sprintf('Удалено просроченных tmp-файлов: %d', $removed));

        return Command::SUCCESS;
    }
}
