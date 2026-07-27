<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

class CoatingSystemLayer
{
    public readonly Uuid $id;

    public function __construct(
        Uuid $id,
        private CoatingSystem $system,
        private Coating $coating,
        private int $position,
        private int $dft,
    ) {
        $this->id = $id;
        $this->assertPositionValid($position);
        $this->assertDftInCoatingRange($dft, $coating);
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getSystem(): CoatingSystem
    {
        return $this->system;
    }

    public function getCoating(): Coating
    {
        return $this->coating;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getDft(): int
    {
        return $this->dft;
    }

    /** @internal вызывается только агрегатом */
    public function changePosition(int $position): void
    {
        $this->assertPositionValid($position);
        $this->position = $position;
    }

    public function changeDft(int $dft): void
    {
        $this->assertDftInCoatingRange($dft, $this->coating);
        $this->dft = $dft;
    }

    private function assertPositionValid(int $position): void
    {
        if ($position < 1) {
            throw new AppException('Позиция слоя должна быть >= 1.');
        }
    }

    private function assertDftInCoatingRange(int $dft, Coating $coating): void
    {
        $range = $coating->getDftRange()->range;
        if (!$range->isWithin($dft)) {
            throw new AppException(sprintf('Толщина слоя %d мкм вне допустимого диапазона покрытия «%s» (%d–%d мкм).', $dft, $coating->getTitle(), $range->getMin(), $range->getMax()));
        }
    }
}
