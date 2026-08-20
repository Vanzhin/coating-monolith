<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById\FindSurfaceTreatmentByIdQuery;
use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

/**
 * После POST-ошибки формы системы покрытий подтягивает человекочитаемые
 * заголовки для async-typeahead (подготовка поверхности + покрытия в слоях),
 * чтобы форма перерисовалась с восстановленными preselected-тегами.
 *
 * Не мапер: делает DB/queryBus-лукапы. Один общий инструмент для Add и Update
 * вместо двух разъехавшихся enrich*-методов в контроллерах.
 */
final readonly class CoatingSystemFormRehydrator
{
    public function __construct(
        private QueryBusInterface $queryBus,
        private CoatingRepositoryInterface $coatingRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $inputData
     *
     * @return array<string, mixed>
     */
    public function enrichInputDataWithTitles(array $inputData): array
    {
        $inputData['surfaceTreatmentTitle'] = $this->treatmentTitle($inputData['surfaceTreatmentId'] ?? null)
            ?? ($inputData['surfaceTreatmentTitle'] ?? '');
        $inputData['coatingTitlesById'] = $this->coatingTitles($inputData['layers'] ?? []);

        return $inputData;
    }

    private function treatmentTitle(mixed $treatmentId): ?string
    {
        if (!is_string($treatmentId) || '' === $treatmentId || !Uuid::isValid($treatmentId)) {
            return null;
        }
        $dto = $this->queryBus->execute(new FindSurfaceTreatmentByIdQuery($treatmentId));

        return null !== $dto ? $dto->title : null;
    }

    /**
     * @return array<string, string>
     */
    private function coatingTitles(mixed $layers): array
    {
        $ids = [];
        foreach ((array) $layers as $layer) {
            $cid = is_array($layer) ? ($layer['coatingId'] ?? null) : null;
            if (is_string($cid) && Uuid::isValid($cid)) {
                $ids[] = $cid;
            }
        }
        if ([] === $ids) {
            return [];
        }

        $titles = [];
        foreach ($this->coatingRepository->findByIds(new StringCollection(...$ids)) as $coating) {
            $dft = $coating->getDftRange();
            $titles[$coating->getId()] = sprintf(
                '%s (%s, %d–%d мкм)',
                $coating->getTitle(),
                $coating->getBase()->value,
                (int) $dft->range->getMin(),
                (int) $dft->range->getMax(),
            );
        }

        return $titles;
    }
}
