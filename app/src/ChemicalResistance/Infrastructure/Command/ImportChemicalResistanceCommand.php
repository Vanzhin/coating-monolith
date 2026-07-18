<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Infrastructure\Command;

use App\ChemicalResistance\Application\Service\ChemicalResistanceImporter;
use App\ChemicalResistance\Application\Service\ImportOptions;
use App\ChemicalResistance\Infrastructure\Docx\DocxAssessmentParser;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'coatings:chemical-resistance:import',
    description: 'Импортирует таблицу химстойкости из docx для указанного покрытия.',
)]
final class ImportChemicalResistanceCommand extends Command
{
    public function __construct(
        private DocxAssessmentParser $parser,
        private ChemicalResistanceImporter $importer,
        private Connection $dbal,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('docx', InputArgument::REQUIRED, 'Путь к .docx файлу')
            ->addOption('coating-title', null, InputOption::VALUE_REQUIRED, 'Точное название покрытия')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Разобрать и напечатать отчёт, ничего не писать')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Перезаписывать существующие оценки')
            ->addOption('default-max-temp', null, InputOption::VALUE_REQUIRED, 'Температура по умолчанию (°C)', 40);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $docx = $input->getArgument('docx');
        $title = $input->getOption('coating-title');

        if ($title === null || $title === '') {
            throw new \InvalidArgumentException('--coating-title обязателен');
        }

        $row = $this->dbal->fetchAssociative('SELECT id::text AS id FROM coatings_coating WHERE title = ?', [$title]);
        if ($row === false) {
            $io->error(sprintf('Покрытие «%s» не найдено.', $title));
            return Command::FAILURE;
        }
        $coatingId = Uuid::fromString($row['id']);

        $parsed = $this->parser->parse($docx);
        $opts = new ImportOptions(
            dryRun: (bool) $input->getOption('dry-run'),
            overwrite: (bool) $input->getOption('overwrite'),
            defaultMaxTemp: (int) $input->getOption('default-max-temp'),
        );
        $report = $this->importer->import($parsed, $coatingId, $opts);

        $io->success(sprintf(
            'Импорт %s: substance created %d / reused %d, assessments created %d / updated %d, notes %d, conflicts %d',
            $opts->dryRun ? '(dry-run)' : '',
            $report->substancesCreated,
            $report->substancesReused,
            $report->assessmentsCreated,
            $report->assessmentsUpdated,
            $report->notesCreated,
            count($report->conflicts),
        ));

        foreach ($report->conflicts as $c) {
            $io->text(" - $c");
        }

        foreach ($report->warnings as $w) {
            $io->warning($w);
        }

        return Command::SUCCESS;
    }
}
