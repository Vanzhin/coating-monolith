<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Command;

use App\Coatings\Application\DTO\Coatings\MixingRatioDTO;
use App\Coatings\Domain\Aggregate\Coating\MixingRatio;
use App\Shared\Domain\Aggregate\ValueObject\PartsRatio;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Собирает доменный MixingRatio из транспортного MixingRatioDTO (каркас для конструкторов VO,
 * без бизнес-правил — они в домене). Обе базы пусты → null (однокомпонентное покрытие).
 *
 * Единственная добавка поверх домена — ЛОКАЦИЯ ошибки: доменные сообщения общие
 * («значение должно быть положительным»), поэтому к ним приклеиваем базу (по объёму/по массе)
 * и номер компонента, чтобы в форме было чётко видно, где проблема.
 */
final readonly class MixingRatioBuilder
{
    public function build(?MixingRatioDTO $dto): ?MixingRatio
    {
        if (null === $dto) {
            return null;
        }

        $byVolume = $this->partsFromBase($dto->volume ?: null, 'по объёму');
        $byMass = $this->partsFromBase($dto->mass ?: null, 'по массе');

        if (null === $byVolume && null === $byMass) {
            return null;
        }

        // Кросс-базовый инвариант (равное число компонентов при обеих базах) — внутри MixingRatio.
        return new MixingRatio($byVolume, $byMass);
    }

    /**
     * @param list<float>|null $parts
     */
    private function partsFromBase(?array $parts, string $baseLabel): ?PartsRatio
    {
        if (null === $parts) {
            return null;
        }

        $numbers = [];
        foreach ($parts as $i => $value) {
            try {
                $numbers[] = new PositiveNumber($value);
            } catch (AppException $e) {
                throw new AppException(sprintf('Соотношение смешивания %s, компонент %d: %s', $baseLabel, $i + 1, $e->getMessage()));
            }
        }

        try {
            return new PartsRatio(...$numbers);
        } catch (AppException $e) {
            throw new AppException(sprintf('Соотношение смешивания %s: %s', $baseLabel, $e->getMessage()));
        }
    }
}
