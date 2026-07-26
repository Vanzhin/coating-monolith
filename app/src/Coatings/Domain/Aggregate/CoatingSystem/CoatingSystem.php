<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Criteria;
use Symfony\Component\Uid\Uuid;

class CoatingSystem extends Aggregate
{
    public readonly Uuid $id;

    private string $title;
    private string $description;
    private Substrate $substrate;
    private SurfacePreparation $surfacePreparation;
    /** @var Collection<int, CoatingSystemLayer> */
    private Collection $layers;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Uuid $id,
        string $title,
        string $description,
        Substrate $substrate,
        SurfacePreparation $surfacePreparation,
        private readonly CoatingSystemChainValidator $chainValidator,
    ) {
        $this->id = $id;
        $this->layers = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->setTitle($title);
        $this->setDescription($description);
        $this->setSubstrate($substrate);
        $this->setSurfacePreparation($surfacePreparation);
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

    public function getSurfacePreparation(): SurfacePreparation
    {
        return $this->surfacePreparation;
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
        $this->substrate = $substrate;
        $this->touch();
    }

    public function setSurfacePreparation(SurfacePreparation $surfacePreparation): void
    {
        $this->surfacePreparation = $surfacePreparation;
        $this->touch();
    }

    /** @return Collection<int, CoatingSystemLayer> */
    public function getLayers(): Collection
    {
        $criteria = Criteria::create()->orderBy(['position' => Criteria::ASC]);
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
            throw new AppException(sprintf(
                'Позиция вставки %d вне диапазона 1..%d.',
                $position, $this->layerCount() + 1,
            ));
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
            throw new AppException(sprintf(
                'Некорректные позиции move: from=%d, to=%d (диапазон 1..%d).',
                $from, $to, $count,
            ));
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

    private function postMutate(): void
    {
        $this->assertPositionsAreDense();
        $this->chainValidator->validate($this);
        $this->touch();
    }

    private function assertPositionsAreDense(): void
    {
        $positions = [];
        foreach ($this->layers as $layer) {
            $positions[] = $layer->getPosition();
        }
        sort($positions);
        $expected = range(1, count($positions));
        if ($positions !== $expected) {
            throw new AppException(sprintf(
                'Позиции слоёв нарушены: [%s], ожидалось [%s].',
                implode(',', $positions), implode(',', $expected),
            ));
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
