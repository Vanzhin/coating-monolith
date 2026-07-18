<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Substance;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class CasNumber implements \Stringable
{
    private function __construct(public string $value) {}

    public static function fromString(string $raw): self
    {
        if (!preg_match('/^(\d{2,7})-(\d{2})-(\d)$/', $raw, $m)) {
            throw new AppException(sprintf(
                'Неверный формат CAS-номера «%s». Ожидается NNNNNNN-NN-N.',
                $raw,
            ));
        }
        [, $left, $mid, $checkDigit] = $m;
        $digits = str_split($left . $mid);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $d) {
            $sum += (int)$d * ($i + 1);
        }
        if ($sum % 10 !== (int)$checkDigit) {
            throw new AppException(sprintf(
                'Неверная контрольная цифра CAS-номера «%s».',
                $raw,
            ));
        }
        return new self($raw);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
