<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Event\CoatingSystemMutated;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Common\Collections\Order;
use Doctrine\Common\Collections\Selectable;
use Symfony\Component\Uid\Uuid;

class CoatingSystem extends Aggregate
{
    public readonly Uuid $id;

    private string $title;
    private string $description;
    private Substrate $substrate;
    /** Среда эксплуатации системы — измерение интервалов перекрытия (см. Coating::minRecoatingFor). */
    private EnvironmentType $environment;
    private ?SurfaceTreatment $surfaceTreatment = null;
    /** @var Collection<int, CoatingSystemLayer>&Selectable<int, CoatingSystemLayer> */
    private Collection $layers;
    /** @var Collection<int, Tag> */
    private Collection $tags;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        string $title,
        string $description,
        Substrate $substrate,
        SurfaceTreatment $surfaceTreatment,
        EnvironmentType $environment = EnvironmentType::Atmospheric,
    ) {
        $this->id = $id;
        $this->layers = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setSubstrate($substrate);
        $this->setSurfaceTreatment($surfaceTreatment);
        $this->setEnvironment($environment);
        // Reset updatedAt and events — setters above emit side-effects but the aggregate
        // is "just created", not yet "mutated" from external perspective.
        $this->updatedAt = $this->createdAt;
        $this->pullEvents();
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSubstrate(): Substrate
    {
        return $this->substrate;
    }

    public function getEnvironment(): EnvironmentType
    {
        return $this->environment;
    }

    public function setEnvironment(EnvironmentType $environment): void
    {
        $this->environment = $environment;
        $this->raise(new CoatingSystemMutated($this->getId()));
        $this->touch();
    }

    public function getSurfaceTreatment(): SurfaceTreatment
    {
        return $this->surfaceTreatment ?? throw new AppException('Подготовка поверхности не задана.');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setTitle(string $title): void
    {
        $trimmed = trim($title);
        if ('' === $trimmed) {
            throw new AppException('Название системы покрытий не может быть пустым.');
        }
        if (mb_strlen($title) > 100) {
            throw new AppException('Название системы покрытий не должно превышать 100 символов.');
        }
        $this->title = $title;
        $this->raise(new CoatingSystemMutated($this->getId()));
        $this->touch();
    }

    public function setDescription(string $description): void
    {
        if (mb_strlen($description) > 2000) {
            throw new AppException('Описание системы покрытий не должно превышать 2000 символов.');
        }
        $this->description = $description;
        $this->raise(new CoatingSystemMutated($this->getId()));
        $this->touch();
    }

    public function setSubstrate(Substrate $substrate): void
    {
        if (null !== $this->surfaceTreatment && !$this->surfaceTreatment->supportsSubstrate($substrate)) {
            throw new AppException(sprintf('Подготовка «%s» применима к [%s], а выбранная подложка — %s.', $this->surfaceTreatment->getCode() ?? $this->surfaceTreatment->getDescription(), implode(', ', array_map(fn (Substrate $s) => $s->title(), $this->surfaceTreatment->getSubstrateScope())), $substrate->title()));
        }
        $this->substrate = $substrate;
        $this->raise(new CoatingSystemMutated($this->getId()));
        $this->touch();
    }

    public function setSurfaceTreatment(SurfaceTreatment $t): void
    {
        if (!$t->supportsSubstrate($this->substrate)) {
            throw new AppException(sprintf('Подготовка «%s» применима к [%s], а в системе выбрана %s.', $t->getCode() ?? $t->getDescription(), implode(', ', array_map(fn (Substrate $s) => $s->title(), $t->getSubstrateScope())), $this->substrate->title()));
        }
        $this->surfaceTreatment = $t;
        $this->raise(new CoatingSystemMutated($this->getId()));
        $this->touch();
    }

    public function setSubstrateAndTreatment(Substrate $substrate, SurfaceTreatment $treatment): void
    {
        if (!$treatment->supportsSubstrate($substrate)) {
            throw new AppException(sprintf('Подготовка «%s» применима к [%s], а выбрана подложка %s.', $treatment->getCode() ?? $treatment->getDescription(), implode(', ', array_map(fn (Substrate $s) => $s->title(), $treatment->getSubstrateScope())), $substrate->title()));
        }
        $this->substrate = $substrate;
        $this->surfaceTreatment = $treatment;
        $this->raise(new CoatingSystemMutated($this->getId()));
        $this->touch();
    }

    /** @return Collection<int, CoatingSystemLayer> */
    public function getLayers(): Collection
    {
        $criteria = Criteria::create()->orderBy(['position' => Order::Ascending]);

        return $this->layers->matching($criteria);
    }

    public function layerCount(): int
    {
        return $this->layers->count();
    }

    public function totalDft(): int
    {
        $sum = 0;
        foreach ($this->layers as $layer) {
            $sum += $layer->getDft();
        }

        return $sum;
    }

    /**
     * Время сборки системы при 20 °C: сумма минимальных интервалов перекрытия между каждой
     * парой соседних слоёв (нижний ждёт перед нанесением верхнего). Интервал учитывает основание
     * верхнего слоя (топкоата), среду эксплуатации системы и пересчитывается под фактическую
     * толщину нижнего слоя. Null, если система пуста или для какой-то пары интервал неизвестен.
     */
    public function minApplicationTimeAt20Minutes(): ?int
    {
        if ($this->layers->isEmpty()) {
            return null;
        }

        $sum = 0;
        foreach ($this->adjacentLayerPairs() as [$under, $over]) {
            $interval = $under->getCoating()->minRecoatingFor(
                $over->getCoating()->getBase(),
                $this->environment,
                $under->getDft(),
            );
            if (null === $interval) {
                return null;
            }
            $sum += $interval;
        }

        return $sum;
    }

    /**
     * Пары соседних слоёв снизу вверх: [нижний, верхний]. Нижний ждёт интервал перекрытия
     * перед нанесением верхнего. Пусто для системы менее чем из двух слоёв.
     *
     * @return \Generator<array{0: CoatingSystemLayer, 1: CoatingSystemLayer}>
     */
    private function adjacentLayerPairs(): \Generator
    {
        $layers = array_values($this->getLayers()->toArray());
        $lastIndex = count($layers) - 1;
        for ($i = 0; $i < $lastIndex; ++$i) {
            yield [$layers[$i], $layers[$i + 1]];
        }
    }

    /**
     * Максимум мин.температуры нанесения по слоям. Null для пустой системы.
     */
    public function maxLayerApplicationMinTemp(): ?int
    {
        if ($this->layers->isEmpty()) {
            return null;
        }
        $max = null;
        foreach ($this->layers as $layer) {
            $temp = $layer->getCoating()->getApplicationMinTemp();
            if (null === $max || $temp > $max) {
                $max = $temp;
            }
        }

        return $max;
    }

    public function complianceMatches(ComplianceEvaluator $evaluator): ComplianceMatches
    {
        return $evaluator->evaluate($this);
    }

    public function firstLayer(): CoatingSystemLayer
    {
        $sorted = array_values($this->getLayers()->toArray());
        if ([] === $sorted) {
            throw new AppException('Система покрытий пуста, слоёв нет.');
        }

        return $sorted[0];
    }

    /** @return iterable<CoatingSystemLayer> */
    public function followupLayers(): iterable
    {
        $sorted = $this->getLayers()->toArray();

        return array_slice($sorted, 1);
    }

    public function appendLayer(Coating $coating, int $dft, ?Color $color = null): CoatingSystemLayer
    {
        $position = $this->layerCount() + 1;
        $layer = new CoatingSystemLayer(Uuid::v7(), $this, $coating, $position, $dft, $color);
        $this->layers->add($layer);
        $this->postMutate();

        return $layer;
    }

    public function insertLayerAt(int $position, Coating $coating, int $dft, ?Color $color = null): CoatingSystemLayer
    {
        if ($position < 1 || $position > $this->layerCount() + 1) {
            throw new AppException(sprintf('Позиция вставки %d вне диапазона 1..%d.', $position, $this->layerCount() + 1));
        }
        foreach ($this->getLayers() as $existing) {
            if ($existing->getPosition() >= $position) {
                $existing->changePosition($existing->getPosition() + 1);
            }
        }
        $layer = new CoatingSystemLayer(Uuid::v7(), $this, $coating, $position, $dft, $color);
        $this->layers->add($layer);
        $this->postMutate();

        return $layer;
    }

    public function removeLayerAt(int $position): void
    {
        $target = null;
        foreach ($this->layers as $layer) {
            if ($layer->getPosition() === $position) {
                $target = $layer;
                break;
            }
        }
        if (null === $target) {
            throw new AppException(sprintf('Слой с позицией %d не найден.', $position));
        }
        $this->layers->removeElement($target);
        foreach ($this->getLayers() as $existing) {
            if ($existing->getPosition() > $position) {
                $existing->changePosition($existing->getPosition() - 1);
            }
        }
        $this->postMutate();
    }

    public function moveLayer(int $from, int $to): void
    {
        if ($from === $to) {
            return;
        }
        $count = $this->layerCount();
        if ($from < 1 || $from > $count || $to < 1 || $to > $count) {
            throw new AppException(sprintf('Некорректные позиции move: from=%d, to=%d (диапазон 1..%d).', $from, $to, $count));
        }
        $target = null;
        foreach ($this->layers as $layer) {
            if ($layer->getPosition() === $from) {
                $target = $layer;
                break;
            }
        }
        if (null === $target) {
            throw new AppException(sprintf('Слой с позицией %d не найден.', $from));
        }
        if ($from < $to) {
            foreach ($this->layers as $layer) {
                if ($layer !== $target && $layer->getPosition() > $from && $layer->getPosition() <= $to) {
                    $layer->changePosition($layer->getPosition() - 1);
                }
            }
        } else {
            foreach ($this->layers as $layer) {
                if ($layer !== $target && $layer->getPosition() >= $to && $layer->getPosition() < $from) {
                    $layer->changePosition($layer->getPosition() + 1);
                }
            }
        }
        $target->changePosition($to);
        $this->postMutate();
    }

    /**
     * Полностью заменяет состав слоёв. Порядок в $items — позиции 1..N.
     * Doctrine удалит старые (orphan-removal="true" в маппинге) и вставит новые.
     * Инварианты (совместимость, плотные позиции) проверяются в postMutate().
     *
     * @param list<array{coating: Coating, dft: int, color?: ?Color}> $items
     */
    public function replaceLayers(array $items): void
    {
        $this->layers->clear();
        foreach ($items as $i => $item) {
            $this->layers->add(new CoatingSystemLayer(Uuid::v7(), $this, $item['coating'], $i + 1, $item['dft'], $item['color'] ?? null));
        }
        $this->postMutate();
    }

    public function updateLayerDft(int $position, int $dft): void
    {
        foreach ($this->layers as $layer) {
            if ($layer->getPosition() === $position) {
                $layer->changeDft($dft);
                $this->postMutate();

                return;
            }
        }
        throw new AppException(sprintf('Слой с позицией %d не найден.', $position));
    }

    /** @return Collection<int, Tag> */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function addTag(Tag $tag): void
    {
        if (!$this->tags->contains($tag)) {
            $this->tags->add($tag);
            $this->raise(new CoatingSystemMutated($this->getId()));
            $this->touch();
        }
    }

    public function removeTag(Tag $tag): void
    {
        if ($this->tags->removeElement($tag)) {
            $this->raise(new CoatingSystemMutated($this->getId()));
            $this->touch();
        }
    }

    /** @param list<Tag> $tags */
    public function replaceTags(array $tags): void
    {
        $this->tags->clear();
        foreach ($tags as $tag) {
            if (!$this->tags->contains($tag)) {
                $this->tags->add($tag);
            }
        }
        $this->raise(new CoatingSystemMutated($this->getId()));
        $this->touch();
    }

    private function assertLayersAreChainable(): void
    {
        $layers = array_values($this->getLayers()->toArray());
        $n = count($layers);
        for ($i = 0; $i < $n - 1; ++$i) {
            $current = $layers[$i]->getCoating()->getBase();
            $next = $layers[$i + 1]->getCoating()->getBase();
            if (!$current->canBecoveredBy($next)) {
                throw new AppException(sprintf('Слой %d (%s) несовместим со слоем %d (%s): поверх %s нельзя наносить %s.', $i + 1, $current->title(), $i + 2, $next->title(), $current->title(), $next->title()));
            }
        }
    }

    private const MAX_LAYERS = 5;

    private function postMutate(): void
    {
        $this->assertLayerCountWithinLimit();
        $this->assertPositionsAreDense();
        $this->assertLayersAreChainable();
        $this->raise(new CoatingSystemMutated($this->getId()));
        $this->touch();
    }

    private function assertLayerCountWithinLimit(): void
    {
        if ($this->layers->count() > self::MAX_LAYERS) {
            throw new AppException(sprintf('Система покрытий не может содержать более %d слоёв.', self::MAX_LAYERS));
        }
    }

    private function assertPositionsAreDense(): void
    {
        $positions = [];
        foreach ($this->layers as $layer) {
            $positions[] = $layer->getPosition();
        }
        sort($positions);
        $n = count($positions);
        $expected = $n > 0 ? range(1, $n) : [];
        if ($positions !== $expected) {
            throw new AppException(sprintf('Позиции слоёв нарушены: [%s], ожидалось [%s].', implode(',', $positions), implode(',', $expected)));
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
