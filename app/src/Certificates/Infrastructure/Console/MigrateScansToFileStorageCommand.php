<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Console;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\File\CertificatePurpose;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Разовый перенос PDF-сканов Certificates из адаптера document_scans в единое хранилище (file_storage).
 * Для каждого документа со старым ключом `<uuid>.pdf`: копирует байты в layout
 * `certificates/scan/{docId}/{uuid}.pdf`, заводит строку реестра stored_file, переводит Document.file
 * с ключа на uuid. Идемпотентно (повторный прогон пропускает уже перенесённые), с --dry-run.
 */
#[AsCommand(
    name: 'app:certificates:migrate-scans-to-file-storage',
    description: 'Перенос сканов Certificates в единое хранилище. Идемпотентно, с --dry-run.',
)]
final class MigrateScansToFileStorageCommand extends Command
{
    public function __construct(
        private readonly FilesystemOperator $scansFilesystem,
        private readonly FilesystemOperator $fileStorageFilesystem,
        private readonly StoredFileRepositoryInterface $registry,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только отчёт, без изменений')
            ->addOption('delete-old', null, InputOption::VALUE_NONE, 'Удалить старый файл из document_scans после копирования');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $deleteOld = (bool) $input->getOption('delete-old');

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($this->documentsWithFile() as $document) {
            $oldKey = $document->getFile();
            // Уже uuid (перенесён) — старый ключ всегда `<uuid>.pdf`.
            if (null === $oldKey || !str_ends_with($oldKey, '.pdf')) {
                ++$skipped;
                continue;
            }
            $uuid = substr($oldKey, 0, -4);
            if (null !== $this->registry->get($uuid)) {
                ++$skipped;
                continue;
            }

            try {
                if (!$this->scansFilesystem->fileExists($oldKey)) {
                    $io->warning(sprintf('Документ %s: файл %s не найден в document_scans', $document->getId(), $oldKey));
                    ++$errors;
                    continue;
                }
                $bytes = $this->scansFilesystem->read($oldKey);
                $stored = StoredFile::stored(
                    $uuid,
                    CertificatePurpose::Scan,
                    (string) $document->getId(),
                    $document->getTitle().'.pdf',
                    'application/pdf',
                    'pdf',
                    \strlen($bytes),
                    new \DateTimeImmutable(),
                );

                if ($dryRun) {
                    $io->writeln(sprintf('[dry-run] %s → %s', $oldKey, $stored->storageKey()));
                    ++$migrated;
                    continue;
                }

                if (!$this->fileStorageFilesystem->fileExists($stored->storageKey())) {
                    $this->fileStorageFilesystem->write($stored->storageKey(), $bytes);
                }
                // Строка реестра и Document.file — в одной транзакции (persist, не registry->add()
                // с его внутренним flush): иначе частичный сбой оставит реестр без переключённого
                // Document.file, а гард идемпотентности навсегда пропустит документ. Байты уже на
                // диске → повторный прогон пропустит write и до-коммитит остальное.
                $this->em->persist($stored);
                $document->setFile($uuid);
                $this->em->flush();

                if ($deleteOld) {
                    // Существование уже подтверждено выше (guard), файл ещё не удалён.
                    $this->scansFilesystem->delete($oldKey);
                }
                ++$migrated;
            } catch (\Throwable $e) {
                $io->error(sprintf('Документ %s (%s): %s', $document->getId(), $oldKey, $e->getMessage()));
                ++$errors;
                // Упавший flush закрывает EntityManager — дальше persist/flush каскадно падают.
                // Прерываемся с честным отчётом; команда идемпотентна, перезапуск дочистит.
                if (!$this->em->isOpen()) {
                    $io->error('EntityManager закрыт после ошибки — прерываю. Перезапустите команду.');
                    break;
                }
            }
        }

        $io->success(sprintf(
            'Мигрировано: %d, пропущено: %d, ошибок: %d%s',
            $migrated,
            $skipped,
            $errors,
            $dryRun ? ' (dry-run)' : '',
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** @return iterable<Document> */
    private function documentsWithFile(): iterable
    {
        return $this->em
            ->createQuery(sprintf('SELECT d FROM %s d WHERE d.file IS NOT NULL', Document::class))
            ->toIterable();
    }
}
