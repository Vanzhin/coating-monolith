<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Color;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Цвет из справочника: свободное название + опциональная привязка к RAL Classic + реальный HEX.
 *
 * Инвариант «RAL↔hex не расходятся»: если задан RAL — HEX выводится из эталона
 * {@see RalClassicPalette} и клиентский HEX игнорируется; цвет без RAL обязан нести свой HEX.
 */
class Color extends Aggregate
{
    public readonly Uuid $id;
    private string $name;
    private ?string $ral;
    private string $hex;

    public function __construct(Uuid $id, string $name, ?string $ralCode = null, ?string $hex = null)
    {
        $this->id = $id;

        $this->setName($name);
        $this->applyColor($ralCode, $hex);
    }

    public function getId(): string
    {
        return $this->id->toRfc4122();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRal(): ?string
    {
        return $this->ral;
    }

    public function getHex(): string
    {
        return $this->hex;
    }

    public function hasRal(): bool
    {
        return null !== $this->ral;
    }

    /**
     * Человекочитаемая подпись цвета: «Светло-серый (RAL 7040)» при наличии RAL,
     * иначе только название. Единый формат отображения для карточек, превью и чипов.
     */
    public function label(): string
    {
        return null !== $this->ral
            ? sprintf('%s (%s)', $this->name, $this->ral)
            : $this->name;
    }

    private function setName(string $name): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new AppException('Название цвета не может быть пустым.');
        }

        AssertService::maxLength($name, 100);
        $this->name = $name;
    }

    private function applyColor(?string $ralCode, ?string $hex): void
    {
        if (null !== $ralCode) {
            $ral = RalClassicPalette::require($ralCode);
            $this->ral = $ral->code;
            $this->hex = $ral->hex->value;

            return;
        }

        if (null === $hex) {
            throw new AppException('Для цвета без RAL нужно указать HEX.');
        }

        $this->ral = null;
        $this->hex = (new HexColor($hex))->value;
    }
}
