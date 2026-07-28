<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Validation;

use App\Shared\Infrastructure\Validation\VO\Error;

final class CoatingSystemErrorFormatter
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
            '[title]' => 'Название',
            '[description]' => 'Описание',
            '[substrate]' => 'Подложка',
            '[surfaceTreatmentId]' => 'Подготовка поверхности',
        ];

        if (isset($map[$path])) {
            return $map[$path];
        }

        if (preg_match('/^\[layers\]\[(\d+)\]\[(coatingId|dft)\]$/', $path, $m)) {
            $layerNum = ((int) $m[1]) + 1;
            $field = 'coatingId' === $m[2] ? 'Покрытие' : 'Толщина (мкм)';

            return sprintf('Слой №%d: %s', $layerNum, $field);
        }

        return $path;
    }
}
