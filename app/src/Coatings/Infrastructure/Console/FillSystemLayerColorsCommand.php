<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Console;

use App\Coatings\Domain\Repository\CoatingSystemRepositoryInterface;
use App\Coatings\Domain\Repository\ColorRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:coating-system:fill-layer-colors', description: 'Backfill: проставить цвет во всех слоях систем без цвета (первый цвет покрытия, для колеруемого без палитры — серый).')]
final class FillSystemLayerColorsCommand extends Command
{
    private const GREY_NAME = 'Серый';
    private const GREY_HEX = '#888888';

    public function __construct(
        private readonly CoatingSystemRepositoryInterface $systemRepo,
        private readonly ColorRepositoryInterface $colorRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Показать, сколько слоёв заполнится, без записи в БД.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $grey = $this->colorRepo->findOneByNameAndHex(self::GREY_NAME, self::GREY_HEX);
        if (null === $grey) {
            $io->error(sprintf('Дефолтный серый (%s / %s) не найден в БД — нечем заполнять слои колеруемых покрытий.', self::GREY_NAME, self::GREY_HEX));

            return Command::FAILURE;
        }

        $systemsChanged = 0;
        $layersFilled = 0;
        $filledWithGrey = 0;

        foreach ($this->systemRepo->findAll() as $system) {
            $items = [];
            $needsFill = false;
            foreach ($system->getLayers() as $layer) {
                $color = $layer->getColor();
                if (null === $color) {
                    $needsFill = true;
                    $fromPalette = $layer->getCoating()->getPossibleColors()->first();
                    if (false === $fromPalette) {
                        $color = $grey;
                        ++$filledWithGrey;
                    } else {
                        $color = $fromPalette;
                    }
                    ++$layersFilled;
                }
                $items[] = ['coating' => $layer->getCoating(), 'dft' => $layer->getDft(), 'color' => $color];
            }

            if (!$needsFill) {
                continue;
            }

            if (!$dryRun) {
                $system->replaceLayers($items);
                $this->systemRepo->save($system);
            }
            ++$systemsChanged;
        }

        $summary = sprintf('Систем затронуто: %d, слоёв заполнено: %d (из них серым: %d).', $systemsChanged, $layersFilled, $filledWithGrey);
        if ($dryRun) {
            $io->note('DRY-RUN, запись в БД не выполнялась. '.$summary);
        } else {
            $io->success($summary);
        }

        return Command::SUCCESS;
    }
}
