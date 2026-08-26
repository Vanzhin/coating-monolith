<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Console;

use App\Certificates\Application\UseCase\Command\CreateDocument\CreateDocumentCommand;
use App\Certificates\Application\UseCase\Command\CreateIssuer\CreateIssuerCommand;
use App\Certificates\Application\UseCase\Command\CreateIssuer\CreateIssuerCommandResult;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Repository\DocumentRepositoryInterface;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommandResult;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\CoatingsFilter;
use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentRepositoryInterface;
use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Импорт заключений из «перечня заключений» (Resources/conclusions.json). На каждую запись
 * создаётся НОВАЯ система покрытий (не матчим существующие) и документ-заключение на неё;
 * издатель резолвится/создаётся по «автору». Идемпотентно по названию системы И номеру заключения
 * (повторный прогон не плодит документы, даже если системы были удалены), с --dry-run.
 * Подложка/среда/даты запечены в JSON при парсинге xlsx (цветов слоёв нет — вариант A).
 */
#[AsCommand(
    name: 'app:certificates:import-conclusions',
    description: 'Импорт заключений (система + документ) из JSON. Идемпотентно, с --dry-run.',
)]
final class ImportConclusionsCommand extends Command
{
    private const DEFAULT_FILE = __DIR__.'/Resources/conclusions.json';

    public function __construct(
        private readonly CoatingRepositoryInterface $coatingRepo,
        private readonly SurfaceTreatmentRepositoryInterface $treatmentRepo,
        private readonly CoatingSystemRepositoryInterface $systemRepo,
        private readonly IssuerRepositoryInterface $issuerRepo,
        private readonly DocumentRepositoryInterface $documentRepo,
        private readonly CommandBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Путь к JSON с заключениями', self::DEFAULT_FILE)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать резолв, ничего не писать');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $entries = $this->readEntries((string) $input->getOption('file'), $io);
        if (null === $entries) {
            return Command::FAILURE;
        }

        $coatingByTitle = $this->coatingIdByNormalizedTitle();
        $treatments = $this->loadTreatments();
        $existingTitles = $this->existingSystemTitles();
        $existingDocNumbers = array_fill_keys($this->documentRepo->existingTitles(), true);

        $issuerCache = [];
        $created = $skipped = $blocked = $failed = [];

        foreach ($entries as $entry) {
            $title = (string) ($entry['systemTitle'] ?? '');
            $conclusion = (string) ($entry['conclusion'] ?? '');

            if ($this->alreadyImported($title, $conclusion, $existingTitles, $existingDocNumbers)) {
                $skipped[] = $title;
                continue;
            }

            $substrate = Substrate::tryFrom((string) ($entry['substrate'] ?? ''));
            $environment = EnvironmentType::tryFrom((string) ($entry['environment'] ?? ''));
            if (null === $substrate || null === $environment) {
                $failed[] = [$title, 'неизвестная подложка/среда'];
                continue;
            }
            if (null === ($entry['issuedAt'] ?? null)) {
                $blocked[] = [$title, 'нет даты выдачи'];
                continue;
            }

            $treatmentId = $this->resolveTreatmentId($substrate, $treatments, $reason);
            if (null === $treatmentId) {
                $failed[] = [$title, $reason];
                continue;
            }

            $layers = $this->resolveLayers($entry['layers'] ?? [], $coatingByTitle, $reason);
            if (null === $layers) {
                $blocked[] = [$title, $reason];
                continue;
            }

            if ($dryRun) {
                $created[] = $title;
                $existingTitles[$title] = true;
                $existingDocNumbers[$conclusion] = true;
                continue;
            }

            try {
                $issuerId = $this->resolveIssuerId((string) $entry['author'], $issuerCache);

                $systemResult = $this->commandBus->execute(new CreateCoatingSystemCommand(
                    title: $title,
                    description: (string) ($entry['subject'] ?? ''),
                    substrate: $substrate,
                    environment: $environment,
                    surfaceTreatmentId: $treatmentId,
                    initialLayers: $layers,
                ));
                \assert($systemResult instanceof CreateCoatingSystemCommandResult);

                $this->commandBus->execute(new CreateDocumentCommand(
                    kind: DocumentKind::Conclusion,
                    title: $conclusion,
                    issuerId: $issuerId,
                    issuedAt: new \DateTimeImmutable((string) $entry['issuedAt']),
                    expiresAt: null !== ($entry['expiresAt'] ?? null) ? new \DateTimeImmutable((string) $entry['expiresAt']) : null,
                    subject: (string) ($entry['subject'] ?? '—'),
                    description: null !== ($entry['description'] ?? null) ? (string) $entry['description'] : null,
                    testStandard: null,
                    references: [new Reference(ReferenceType::CoatingSystem, Uuid::fromString($systemResult->id))],
                ));

                $created[] = $title;
                $existingTitles[$title] = true;
                $existingDocNumbers[$conclusion] = true;
            } catch (\Throwable $e) {
                $failed[] = [$title, $e->getMessage()];
            }
        }

        $this->report($io, $dryRun, $created, $skipped, $blocked, $failed);

        return Command::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function readEntries(string $file, SymfonyStyle $io): ?array
    {
        if (!is_readable($file)) {
            $io->error(sprintf('Файл не найден или недоступен: %s', $file));

            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $io->error(sprintf('Некорректный JSON: %s', $e->getMessage()));

            return null;
        }
        if (!is_array($data)) {
            $io->error('Ожидался JSON-массив заключений.');

            return null;
        }

        return $data;
    }

    /**
     * @param array<string, string> $cache автор → issuerId
     */
    private function resolveIssuerId(string $author, array &$cache): string
    {
        $author = trim($author);
        if (isset($cache[$author])) {
            return $cache[$author];
        }
        $existing = $this->issuerRepo->findOneByTitle($author);
        if (null !== $existing) {
            return $cache[$author] = $existing->getId();
        }
        $result = $this->commandBus->execute(new CreateIssuerCommand($author));
        \assert($result instanceof CreateIssuerCommandResult);

        return $cache[$author] = $result->id;
    }

    /**
     * @param list<mixed>           $layers
     * @param array<string, string> $coatingByTitle
     *
     * @return list<array{coatingId: string, dft: int}>|null
     */
    private function resolveLayers(array $layers, array $coatingByTitle, ?string &$reason): ?array
    {
        $reason = null;
        if ([] === $layers) {
            $reason = 'нет слоёв';

            return null;
        }

        $resolved = [];
        foreach ($layers as $layer) {
            $materials = is_array($layer) ? ($layer['materials'] ?? []) : [];
            if (!is_array($materials) || [] === $materials) {
                $reason = 'битые данные слоя';

                return null;
            }
            $coatingId = null;
            foreach ($materials as $material) {
                $key = ImportSupport::normalizeTitle((string) $material);
                if (isset($coatingByTitle[$key])) {
                    $coatingId = $coatingByTitle[$key];
                    break;
                }
            }
            if (null === $coatingId) {
                $reason = sprintf('нет покрытия в БД: %s', implode(' / ', array_map('strval', $materials)));

                return null;
            }

            $dft = ImportSupport::maxDft((string) ($layer['dft'] ?? ''));
            if (null === $dft) {
                $reason = 'ТСП не указан';

                return null;
            }

            $resolved[] = ['coatingId' => $coatingId, 'dft' => $dft];
        }

        return $resolved;
    }

    /** @return array<string, string> */
    private function coatingIdByNormalizedTitle(): array
    {
        $map = [];
        $result = $this->coatingRepo->findByFilter(new CoatingsFilter(pager: new Pager(1, 5000)));
        foreach ($result->items as $coating) {
            $map[ImportSupport::normalizeTitle($coating->getTitle())] = $coating->getId();
        }

        return $map;
    }

    /** @return list<SurfaceTreatment> */
    private function loadTreatments(): array
    {
        $treatments = [];
        foreach ($this->treatmentRepo->list(new SurfaceTreatmentsFilter(), 5000, 0) as $treatment) {
            $treatments[] = $treatment;
        }

        return $treatments;
    }

    /**
     * Подготовка под подложку: для стали — Sa 2½ (код «Sa 2 1/2»); для прочих — первая,
     * чей substrate_scope покрывает подложку (бетон → бескодовая бетонная).
     * Null + $reason, если подходящей нет.
     *
     * @param list<SurfaceTreatment> $all
     */
    private function resolveTreatmentId(Substrate $substrate, array $all, ?string &$reason): ?string
    {
        $reason = null;

        $preferredCode = Substrate::STEEL_CARBON === $substrate ? 'Sa 2 1/2' : null;
        if (null !== $preferredCode) {
            foreach ($all as $treatment) {
                if ($preferredCode === $treatment->getCode() && $treatment->supportsSubstrate($substrate)) {
                    return $treatment->getId();
                }
            }
        }

        foreach ($all as $treatment) {
            if ($treatment->supportsSubstrate($substrate)) {
                return $treatment->getId();
            }
        }
        $reason = sprintf('нет подготовки для подложки %s', $substrate->title());

        return null;
    }

    /** @return array<string, true> */
    private function existingSystemTitles(): array
    {
        $titles = [];
        foreach ($this->systemRepo->findAll() as $system) {
            $titles[$system->getTitle()] = true;
        }

        return $titles;
    }

    /**
     * Заключение уже импортировано: есть система с таким названием ИЛИ документ с таким номером
     * (последнее ловит дубли даже при удалённых системах — повторный прогон безопасен).
     *
     * @param array<string, true> $existingSystemTitles
     * @param array<string, true> $existingDocNumbers
     */
    private function alreadyImported(
        string $systemTitle,
        string $conclusion,
        array $existingSystemTitles,
        array $existingDocNumbers,
    ): bool {
        return isset($existingSystemTitles[$systemTitle])
            || ('' !== $conclusion && isset($existingDocNumbers[$conclusion]));
    }

    /**
     * @param list<string>                   $created
     * @param list<string>                   $skipped
     * @param list<array{0:string,1:string}> $blocked
     * @param list<array{0:string,1:string}> $failed
     */
    private function report(SymfonyStyle $io, bool $dryRun, array $created, array $skipped, array $blocked, array $failed): void
    {
        if ([] !== $blocked) {
            $io->section('Заблокированы (пропущены)');
            $io->table(['Система', 'Причина'], $blocked);
        }
        if ([] !== $failed) {
            $io->section('Ошибка (пропущены)');
            $io->table(['Система', 'Ошибка'], $failed);
        }

        $verb = $dryRun ? 'будет создано' : 'создано';
        $io->success(sprintf(
            '%s: %d | пропущено (уже есть): %d | заблокировано: %d | ошибок: %d',
            ucfirst($verb),
            count($created),
            count($skipped),
            count($blocked),
            count($failed),
        ));
    }
}
