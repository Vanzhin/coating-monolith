<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Validation;

use App\Shared\Infrastructure\Validation\VO\Error;

final class SurfaceTreatmentErrorFormatter
{
    /**
     * @param Error[] $errors
     */
    public function format(array $errors): string
    {
        $lines = [];
        foreach ($errors as $error) {
            $label = $this->labelFor($error->getProperty());
            $lines[] = sprintf('%s: %s', $label, $error->getMessage());
        }

        return implode("\n", $lines);
    }

    private function labelFor(string $path): string
    {
        $map = [
            '[description]' => 'Описание',
            '[code]' => 'Код',
            '[standardCode]' => 'Стандарт',
            '[substrateScope]' => 'Применимые подложки',
        ];

        if (isset($map[$path])) {
            return $map[$path];
        }

        if (preg_match('/^\[substrateScope\]\[(\d+)\]$/', $path, $m)) {
            $num = ((int) $m[1]) + 1;

            return sprintf('Применимая подложка №%d', $num);
        }

        return $path;
    }
}
