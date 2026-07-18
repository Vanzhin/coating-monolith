<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class AssessmentTemperature
{
    private const MIN = 1;
    private const MAX = 500;
    private const DEFAULT = 40;

    private function __construct(public int $celsius) {}

    public static function fromInt(int $celsius): self
    {
        if ($celsius < self::MIN || $celsius > self::MAX) {
            throw new AppException(sprintf(
                'Температура %d °C вне допустимого диапазона %d..%d.',
                $celsius, self::MIN, self::MAX,
            ));
        }
        return new self($celsius);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT);
    }
}
