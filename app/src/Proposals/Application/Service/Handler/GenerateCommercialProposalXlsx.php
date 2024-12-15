<?php
declare(strict_types=1);


namespace App\Proposals\Application\Service\Handler;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoItem;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoUnit;
use App\Proposals\Domain\Aggregate\ProposalDocument\ProposalDocument;
use App\Proposals\Infrastructure\Adapter\CoatingsAdapter;
use App\Shared\Domain\Service\AssertService;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\RowCellIterator;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GenerateCommercialProposalXlsx
{
    private string $sheetName = 'ТКП_ПЕЧАТЬ';
    private int $totalDft = 0;
    private float $totalCoatPricePerUnit = 0;
    private float $totalTheoreticalCoatPricePerSqMeter = 0;
    private float $totalPracticalCoatPricePerSqMeter = 0;
    private float $totalCoatQuantity = 0;
    private float $totalCoatPrice = 0;
    private float $totalThinnerQuantity = 0;
    private float $totalThinnerPrice = 0;
    private float $grandTotal = 0;

    public function __construct(
        private readonly CoatingsAdapter $adapter,
    )
    {
    }

    public function generate(ProposalDocument $document): Spreadsheet
    {
        $coatsTitleData = [];
        $coatsCalcData = [];
        $thinnerData = [];

        $spreadsheet = IOFactory::load($document->getTemplate()->getPath());
        $sheet = $spreadsheet->getSheetByName($this->sheetName);
        foreach ($sheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(true);
            foreach ($cellIterator as $cell) {
                if ($cell->getValue() === 'Проект:') {
                    $this->setNextCellValue($sheet, $cellIterator, $document->getProposalInfo()->getProjectTitle());
                    break;
                }
                if ($cell->getValue() === 'Система:') {
                    $this->setNextCellValue($sheet, $cellIterator, $document->getProposalInfo()->getProjectStructureDescription());
                    break;
                }
                if ($cell->getValue() === 'Прогнозируемый срок эксплуатации:') {
                    $this->setNextCellValue($sheet, $cellIterator, $document->getProposalInfo()->getDurability()->value);
                    break;
                }
                if ($cell->getValue() === 'Площадь под окраску, м2:') {
                    $this->setNextCellValue($sheet, $cellIterator, $document->getProposalInfo()->getProjectArea());
                    break;
                }
                if ($cell->getValue() === 'Потери материала, %:') {
                    $this->setNextCellValue($sheet, $cellIterator, $document->getProposalInfo()->getLoss());
                    break;
                }
                if ($cell->getValue() === 'Материалы') {
                    /** @var GeneralProposalInfoItem $coat */
                    foreach ($document->getProposalInfo()->getCoats() as $coat) {
                        $dto = $this->adapter->getCoating($coat->getCoatId())->coatingDTO;
                        AssertService::notNull($dto, 'Одно из покрытий формы не найдено.');
                        $coatsTitleData[] = [$dto->title, '- ' . mb_substr($dto->description, 0, 100)];

                        $coatsCalcData[] = [
                            $dto->title,
                            $coat->getCoatColor(),
                            $coat->getCoatDft(),
                            $dto->volumeSolid,
                            $tsr = $this->calculateSpreadingRate(
                                $coat->getCoatDft(),
                                $dto->volumeSolid,
                                $dto->massDensity,
                                $document->getProposalInfo()->getUnit(),
                            ),
                            $psr = $this->calculateSpreadingRate(
                                $coat->getCoatDft(),
                                $dto->volumeSolid,
                                $dto->massDensity,
                                $document->getProposalInfo()->getUnit(),
                                $document->getProposalInfo()->getLoss()
                            ),
                            $coat->getCoatPrice(),
                            $theoreticalPricePerSqMeter = $coat->getCoatPrice() * $tsr,
                            $practicalPricePerSqMeter = $coat->getCoatPrice() * $psr,
                            $quantity = ceil($document->getProposalInfo()->getProjectArea() * $psr / $dto->pack) * $dto->pack,
                            $coatPrice = $coat->getCoatPrice() * $quantity

                        ];
                        $thinnerData[] = [
                            'Разбавитель ' . $dto->thinner . ' для ' . $dto->title,
                            null, null, null,
                            $coat->getThinnerConsumption() / 100,
                            null, null,
                            $coat->getThinnerPrice(),
                            null,
                            $thinnerQuantity = ceil($quantity * $coat->getThinnerConsumption() / 100 / 20) * 20,
                            $thinnerPrice = $thinnerQuantity * $coat->getThinnerPrice()
                        ];

                        //добиваю итого
                        $this->totalDft += $coat->getCoatDft();
                        $this->totalCoatPricePerUnit += $coat->getCoatPrice();
                        $this->totalTheoreticalCoatPricePerSqMeter += $theoreticalPricePerSqMeter;
                        $this->totalPracticalCoatPricePerSqMeter += $practicalPricePerSqMeter;
                        $this->totalCoatQuantity += $quantity;
                        $this->totalCoatPrice += $coatPrice;
                        $this->totalThinnerQuantity += $thinnerQuantity;
                        $this->totalThinnerPrice += $thinnerPrice;
                        $this->grandTotal += $this->totalCoatPrice + $this->totalThinnerPrice;
                    }
                    $sheet->fromArray(
                        $coatsTitleData,  // The data to set
                        null,        // Array values with this value will not be set
                        $cell->getColumn() . ($cell->getRow() + 1)         // Top left coordinate of the worksheet range where
                    );
                    break;
                }
                if ($cell->getValue() === 'Сухой остаток, %') {
                    $unitData = [
                        [
                            sprintf('Теор. расход, %s/м2', $document->getProposalInfo()->getUnit()->value),
                            sprintf('Расход с учетом потерь, %s/м2', $document->getProposalInfo()->getUnit()->value),
                            sprintf('Цена, руб/%s', $document->getProposalInfo()->getUnit()->value),
                            null,
                            null,
                            sprintf('Кол-во %s на окр. площадь', $document->getProposalInfo()->getUnit()->value),
                        ],
                    ];
                    $sheet->fromArray(
                        $unitData,  // The data to set
                        null,        // Array values with this value will not be set
                        Coordinate::stringFromColumnIndex($cellIterator->getCurrentColumnIndex() + 1)
                        . $cell->getRow()     // Top left coordinate of the worksheet range where
                    );
                    break;
                }
                if ($cell->getValue() === 'Материал') {
                    $sheet->fromArray(
                        $coatsCalcData,  // The data to set
                        null,        // Array values with this value will not be set
                        $cell->getColumn() . ($cell->getRow() + 1)         // Top left coordinate of the worksheet range where
                    );
                    continue;
                }
                if ($cell->getValue() === 'Подитог ЛКМ') {
                    $sheet->fromArray(
                        [
                            null, null, $this->totalDft, null, null, null,
                            $this->totalCoatPricePerUnit,
                            $this->totalTheoreticalCoatPricePerSqMeter,
                            $this->totalPracticalCoatPricePerSqMeter,
                            $this->totalCoatQuantity,
                            $this->totalCoatPrice
                        ],  // The data to set
                        null,        // Array values with this value will not be set
                        $cell->getColumn() . ($cell->getRow())         // Top left coordinate of the worksheet range where
                    );
                    continue;
                }
                if ($cell->getValue() === 'Растворители для разбавления (опционально)') {
                    $sheet->fromArray(
                        $thinnerData,  // The data to set
                        null,        // Array values with this value will not be set
                        $cell->getColumn() . ($cell->getRow() + 1)         // Top left coordinate of the worksheet range where
                    );
                    continue;
                }
                if ($cell->getValue() === 'Подитог р-ль') {
                    $sheet->fromArray(
                        [
                            null, null, null, null, null, null, null, null, null,
                            $this->totalThinnerQuantity,
                            $this->totalThinnerPrice,
                        ],  // The data to set
                        null,        // Array values with this value will not be set
                        $cell->getColumn() . ($cell->getRow())         // Top left coordinate of the worksheet range where
                    );
                    continue;
                }
                if ($cell->getValue() === 'Итого стоимость ЛКМ') {
                    $sheet->fromArray(
                        [
                            null, null, null, null, null, null, null, null, null, null,
                            $this->grandTotal,
                        ],  // The data to set
                        null,        // Array values with this value will not be set
                        $cell->getColumn() . ($cell->getRow())         // Top left coordinate of the worksheet range where
                    );
                    continue;
                }
            }
        }

        return $spreadsheet;
    }

    private function setNextCellValue(Worksheet $sheet, RowCellIterator $cellIterator, int|float|string $value): void
    {
        $sheet->setCellValue(
            [$cellIterator->getCurrentColumnIndex() + 1, $cellIterator->current()->getRow()],
            $value);
    }

    private function calculateSpreadingRate(int $dft, int $vs, float $massDensity, GeneralProposalInfoUnit $unit, int $loss = 0): float
    {
        $tsr = $dft / 10 / $vs * (100 / (100 - $loss));
        if ($unit->value === 'кг') {
            $tsr *= $massDensity;
        }

        return $tsr;
    }
}