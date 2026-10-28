<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Console;

use App\Inquiries\Application\Service\SystemSelection\SystemSelectionProcessor;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Factory\InquiryFactory;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\FileText\FileTextExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Сквозной прогон движка подбора систем БЕЗ сохранения и очереди: текст (или файл → извлечение) →
 * реальный NetAngels → резолв веществ → поиск Coatings → печать распознанного/ответа/предупреждений.
 * Инструмент обкатки на живых запросах (как ручной стенд), не прод-путь.
 */
#[AsCommand(
    name: 'app:inquiry:smoke',
    description: 'Прогнать конвейер подбора на тексте или файле (реальные NetAngels + поиск), напечатать результат',
)]
final class ProcessInquirySmokeCommand extends Command
{
    public function __construct(
        private readonly InquiryFactory $factory,
        private readonly SystemSelectionProcessor $processor,
        private readonly FileTextExtractor $extractor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('text', InputArgument::OPTIONAL, 'Текст запроса (если без --file)')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Путь к файлу PDF/DOCX/XLSX — извлечём текст');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $text = $this->resolveText($input, $io);
        if (null === $text) {
            return Command::INVALID;
        }

        $inquiry = $this->factory->create(InquiryType::SystemSelection, 'system', $text, new StringCollection(), new \DateTimeImmutable());
        $this->processor->process($inquiry);

        $io->section('Статус: '.$inquiry->getStatus()->label());
        if (InquiryStatus::Failed === $inquiry->getStatus()) {
            $io->error((string) $inquiry->getFailureReason());

            return Command::FAILURE;
        }

        $source = $inquiry->getParameterSource();
        if (null !== $source) {
            $io->section('Провенанс: '.$source->strategy->label());
            $io->writeln((string) json_encode($source->details, \JSON_UNESCAPED_UNICODE));
        }

        $io->section('Распознанные параметры');
        $io->writeln((string) json_encode($inquiry->getRecognizedParameters()?->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT));

        $answer = $inquiry->getAnswer();
        $io->section(sprintf('Подобрано систем: %d', null !== $answer ? $answer->total : 0));
        $systems = null !== $answer ? array_slice($answer->systems(), 0, 15) : [];
        foreach ($systems as $system) {
            $io->writeln(sprintf('  %s — %s', $system->id, $system->label));
        }

        if ([] !== $inquiry->getWarnings()) {
            $io->section('Предупреждения');
            $io->listing($inquiry->getWarnings());
        }

        return Command::SUCCESS;
    }

    private function resolveText(InputInterface $input, SymfonyStyle $io): ?string
    {
        $file = $input->getOption('file');
        if (is_string($file) && '' !== $file) {
            $fileText = $this->extractor->extract($file);
            if (!$fileText->isExtracted()) {
                $io->error(sprintf('Текст из файла не извлечён (%s): %s', $fileText->status->name, $fileText->error ?? '—'));

                return null;
            }

            return $fileText->text;
        }

        $text = $input->getArgument('text');
        if (is_string($text) && '' !== trim($text)) {
            return $text;
        }

        $io->error('Нужен текст аргументом или --file=путь.');

        return null;
    }
}
