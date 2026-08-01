<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Coatings\Domain\Aggregate\Tag\Tag;
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
    private ?SurfaceTreatment $surfaceTreatment = null;
    /** @var Collection<int, CoatingSystemLayer>&Selectable<int, CoatingSystemLayer> */
    private Collection $layers;
    /** @var Collection<int, Tag> */
    private Collection $tags;
    /** Кешируемая производная величина: время сборки системы при 20 °C, минут. */
    private ?int $minBuildingTimeAt20Minutes = null;
    /** Кешируемая производная величина: мин.температура нанесения системы (max по слоям), °C. */
    private ?int $maxLayerApplicationMinTemp = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        string $title,
        string $description,
        Substrate $substrate,
        SurfaceTreatment $surfaceTreatment,
        private ?CoatingSystemChainValidatorInterface $chainValidator = null,
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
        // Reset updatedAt to match createdAt — setters above call touch() but that's
        // an implementation side-effect; the aggregate is "just created", not "mutated".
        $this->updatedAt = $this->createdAt;
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
        $this->touch();
    }

    public function setDescription(string $description): void
    {
        if (mb_strlen($description) > 2000) {
            throw new AppException('Описание системы покрытий не должно превышать 2000 символов.');
        }
        $this->description = $description;
        $this->touch();
    }

    public function setSubstrate(Substrate $substrate): void
    {
        if (null !== $this->surfaceTreatment && !$this->surfaceTreatment->supportsSubstrate($substrate)) {
            throw new AppException(sprintf('Подготовка «%s» применима к [%s], а выбранная подложка — %s.', $this->surfaceTreatment->getCode() ?? $this->surfaceTreatment->getDescription(), implode(', ', array_map(fn (Substrate $s) => $s->title(), $this->surfaceTreatment->getSubstrateScope())), $substrate->title()));
        }
        $this->substrate = $substrate;
        $this->touch();
    }

    public function setSurfaceTreatment(SurfaceTreatment $t): void
    {
        if (!$t->supportsSubstrate($this->substrate)) {
            throw new AppException(sprintf('Подготовка «%s» применима к [%s], а в системе выбрана %s.', $t->getCode() ?? $t->getDescription(), implode(', ', array_map(fn (Substrate $s) => $s->title(), $t->getSubstrateScope())), $this->substrate->title()));
        }
        $this->surfaceTreatment = $t;
        $this->touch();
    }

    public function setSubstrateAndTreatment(Substrate $substrate, SurfaceTreatment $treatment): void
    {
        if (!$treatment->supportsSubstrate($substrate)) {
            throw new AppException(sprintf('Подготовка «%s» применима к [%s], а выбрана подложка %s.', $treatment->getCode() ?? $treatment->getDescription(), implode(', ', array_map(fn (Substrate $s) => $s->title(), $treatment->getSubstrateScope())), $substrate->title()));
        }
        $this->substrate = $substrate;
        $this->surfaceTreatment = $treatment;
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
     * Кешированная величина, пересчитывается автоматически при любой мутации слоёв
     * (см. `recalculateDerivedFields` из `postMutate`).
     * Семантика — время сборки системы при 20 °C: сумма пересчитанных под фактическую
     * толщину интервалов перекрытия по слоям, поверх которых наносится следующий.
     * Null, если у любого из этих слоёв нет базовой точки при 20 °C (legacy до инварианта Coating),
     * либо система пуста. Один слой — 0.
     */
    public function getMinBuildingTimeAt20Minutes(): ?int
    {
        return $this->minBuildingTimeAt20Minutes;
    }

    /**
     * Кешированная величина, пересчитывается автоматически при мутации слоёв.
     * Максимум мин.температуры нанесения по слоям: система работает при температуре,
     * при которой можно наносить самый требовательный из её слоёв. Null для пустой системы.
     */
    public function getMaxLayerApplicationMinTemp(): ?int
    {
        return $this->maxLayerApplicationMinTemp;
    }

    private function recalculateDerivedFields(): void
    {
        $this->minBuildingTimeAt20Minutes = $this->computeMinBuildingTimeAt20Minutes();
        $this->maxLayerApplicationMinTemp = $this->computeMaxLayerApplicationMinTemp();
    }

    private function computeMinBuildingTimeAt20Minutes(): ?int
    {
        $ordered = $this->getLayers();
        if ($ordered->isEmpty()) {
            return null;
        }
        $topLayer = $ordered->last();

        $sum = 0;
        foreach ($ordered as $layer) {
            if ($layer === $topLayer) {
                continue; // верхний слой ничем не покрывается — его интервал не участвует
            }
            $interval = $layer->getCoating()->interpolatedMinRecoatMinutesAt20($layer->getDft());
            if (null === $interval) {
                return null;
            }
            $sum += $interval;
        }

        return $sum;
    }

    private function computeMaxLayerApplicationMinTemp(): ?int
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

    public function firstLayer(): CoatingSystemLayer
    {
        $sorted = $this->getLayers()->toArray();
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

    public function appendLayer(Coating $coating, int $dft): CoatingSystemLayer
    {
        $position = $this->layerCount() + 1;
        $layer = new CoatingSystemLayer(Uuid::v7(), $this, $coating, $position, $dft);
        $this->layers->add($layer);
        $this->postMutate();

        return $layer;
    }

    public function insertLayerAt(int $position, Coating $coating, int $dft): CoatingSystemLayer
    {
        if ($position < 1 || $position > $this->layerCount() + 1) {
            throw new AppException(sprintf('Позиция вставки %d вне диапазона 1..%d.', $position, $this->layerCount() + 1));
        }
        foreach ($this->getLayers() as $existing) {
            if ($existing->getPosition() >= $position) {
                $existing->changePosition($existing->getPosition() + 1);
            }
        }
        $layer = new CoatingSystemLayer(Uuid::v7(), $this, $coating, $position, $dft);
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
            $this->touch();
        }
    }

    public function removeTag(Tag $tag): void
    {
        if ($this->tags->removeElement($tag)) {
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
        $this->touch();
    }

    public function setChainValidator(CoatingSystemChainValidatorInterface $validator): void
    {
        $this->chainValidator = $validator;
    }

    private function postMutate(): void
    {
        $this->assertPositionsAreDense();
        if (null === $this->chainValidator) {
            throw new AppException('Валидатор цепочки слоёв не установлен.');
        }
        $this->chainValidator->validate($this);
        $this->recalculateDerivedFields();
        $this->touch();
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
