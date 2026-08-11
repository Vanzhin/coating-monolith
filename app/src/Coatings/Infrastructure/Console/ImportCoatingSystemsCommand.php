<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Console;

use App\Coatings\Application\UseCase\Command\CreateCoatingSystem\CreateCoatingSystemCommand;
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

#[AsCommand(
    name: 'app:coating-system:import',
    description: 'Импорт систем покрытий из JSON (Resources/coating_systems.json). Идемпотентно, с --dry-run.',
)]
final class ImportCoatingSystemsCommand extends Command
{
    private const DEFAULT_FILE = __DIR__.'/Resources/coating_systems.json';

    public function __construct(
        private readonly CoatingRepositoryInterface $coatingRepo,
        private readonly SurfaceTreatmentRepositoryInterface $treatmentRepo,
        private readonly CoatingSystemRepositoryInterface $systemRepo,
        private readonly CommandBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Путь к JSON с системами', self::DEFAULT_FILE)
            ->addOption('surface-treatment', null, InputOption::VALUE_REQUIRED, 'Код подготовки поверхности для всех систем (переопределяет значение из файла)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только показать резолв, ничего не писать');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $file = (string) $input->getOption('file');
        $overrideRaw = $input->getOption('surface-treatment');
        $treatmentOverride = null !== $overrideRaw ? (string) $overrideRaw : null;

        $systems = $this->readSystems($file, $io);
        if (null === $systems) {
            return Command::FAILURE;
        }

        $coatingByTitle = $this->coatingIdByNormalizedTitle();
        $treatments = $this->loadTreatments();
        $treatmentByCode = [];
        foreach ($treatments as $treatment) {
            $code = $treatment->getCode();
            if (null !== $code) {
                $treatmentByCode[$code] = $treatment;
            }
        }
        $existingTitles = $this->existingSystemTitles();

        $created = [];
        $skipped = [];
        $blocked = [];
        $failed = [];

        foreach ($systems as $sys) {
            $title = (string) ($sys['title'] ?? '');

            if (isset($existingTitles[$title])) {
                $skipped[] = $title;
                continue;
            }

            $substrate = Substrate::tryFrom((string) ($sys['substrate'] ?? ''));
            if (null === $substrate) {
                $failed[] = [$title, sprintf('неизвестная подложка «%s»', (string) ($sys['substrate'] ?? ''))];
                continue;
            }

            // Среда эксплуатации запечена в JSON из заголовка секции — как и подложка.
            // Пустое/неизвестное значение не глушим в atmospheric: это опечатка данных.
            $environment = EnvironmentType::tryFrom((string) ($sys['environment'] ?? ''));
            if (null === $environment) {
                $failed[] = [$title, sprintf('неизвестная среда «%s»', (string) ($sys['environment'] ?? ''))];
                continue;
            }

            $treatmentId = $this->resolveTreatmentId(
                $substrate,
                $treatmentOverride,
                (string) ($sys['surfaceTreatmentCode'] ?? ''),
                $treatmentByCode,
                $treatments,
                $reason,
            );
            if (null === $treatmentId) {
                $failed[] = [$title, $reason];
                continue;
            }

            $layers = $this->resolveLayers($sys['layers'] ?? [], $coatingByTitle, $reason);
            if (null === $layers) {
                $blocked[] = [$title, $reason];
                continue;
            }

            if ($dryRun) {
                $created[] = $title;
                $existingTitles[$title] = true;
                continue;
            }

            try {
                $this->commandBus->execute(new CreateCoatingSystemCommand(
                    title: $title,
                    description: (string) ($sys['description'] ?? ''),
                    substrate: $substrate,
                    environment: $environment,
                    surfaceTreatmentId: $treatmentId,
                    initialLayers: $layers,
                ));
                $created[] = $title;
                $existingTitles[$title] = true;
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
    private function readSystems(string $file, SymfonyStyle $io): ?array
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
            $io->error('Ожидался JSON-массив систем.');

            return null;
        }

        return $data;
    }

    /**
     * Резолвит слои системы в пары {coatingId, dft}. Возвращает null и заполняет
     * $reason, если хотя бы один слой не сматчился (нет покрытия / нечисловой ТСП).
     *
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
                $reason = sprintf('ТСП не числовой: «%s»', (string) ($layer['dft'] ?? ''));

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
     * Подбирает id подготовки поверхности под подложку системы. Приоритет:
     * 1) флаг --surface-treatment (жёсткое переопределение, обязан покрывать подложку);
     * 2) код из JSON (Sa 2 1/2), если его подготовка покрывает подложку — так углеродистая
     *    сталь идёт на Sa 2 1/2 как раньше;
     * 3) иначе первая подготовка, чей substrate_scope покрывает подложку (бетон/оцинковка/
     *    напыление → соответствующая бескодовая подготовка, резолвится по её id).
     * Возвращает null и пишет $reason, если подходящей подготовки нет.
     *
     * @param array<string, SurfaceTreatment> $byCode
     * @param list<SurfaceTreatment>          $all
     */
    private function resolveTreatmentId(
        Substrate $substrate,
        ?string $override,
        string $jsonCode,
        array $byCode,
        array $all,
        ?string &$reason,
    ): ?string {
        $reason = null;

        if (null !== $override) {
            $treatment = $byCode[$override] ?? null;
            if (null === $treatment) {
                $reason = sprintf('подготовка поверхности «%s» не найдена в БД', $override);

                return null;
            }
            if (!$treatment->supportsSubstrate($substrate)) {
                $reason = sprintf('подготовка «%s» не покрывает подложку %s', $override, $substrate->title());

                return null;
            }

            return $treatment->getId();
        }

        $coded = '' !== $jsonCode ? ($byCode[$jsonCode] ?? null) : null;
        if (null !== $coded && $coded->supportsSubstrate($substrate)) {
            return $coded->getId();
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
            $io->section('Ошибка доменной валидации (пропущены)');
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
