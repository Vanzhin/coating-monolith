<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Present;

use App\Shared\Domain\Audit\AuditFieldKind;
use App\Shared\Domain\Audit\ChangeOp;
use App\Shared\Domain\Audit\FieldChange;

/**
 * Рендерит один {@see FieldChange} в подпись+было/стало, маршрутизируя ПО ВИДУ
 * ({@see AuditFieldKind}) и СТРУКТУРЕ хвоста пути (сегменты после имени поля) —
 * доменно-слепо: имена полей (dryToTouch, minRecoatingInterval, …) сюда приходят
 * только как данные ($fieldLabel), сам класс их не знает и не хардкодит.
 * Возврат null — сигнал вызывающей стороне СКРЫТЬ изменение (шум is_calculated).
 */
final class AuditChangePresenter
{
    public function __construct(
        private readonly ValueHumanizer $humanizer,
        private readonly DurationHumanizer $duration,
    ) {
    }

    public function present(FieldChange $c, string $fieldLabel, AuditFieldKind $kind): ?PresentedChange
    {
        $tail = array_slice(explode('.', $c->path), 1);

        return match ($kind) {
            AuditFieldKind::Scalar => $this->presentScalar($c, $fieldLabel, $tail),
            AuditFieldKind::DurationSeries => $this->presentDurationSeries($c, $fieldLabel, $tail),
            AuditFieldKind::RecoatingTree => $this->presentRecoatingTree($c, $fieldLabel, $tail),
            AuditFieldKind::Dft => $this->presentDft($c, $fieldLabel, $tail),
            AuditFieldKind::Thermal => $this->presentThermal($c, $fieldLabel, $tail),
            AuditFieldKind::Mixing => $this->presentMixing($c, $fieldLabel, $tail),
        };
    }

    /** @param list<string> $tail */
    private function presentScalar(FieldChange $c, string $fieldLabel, array $tail): PresentedChange
    {
        if ([] === $tail) {
            return $this->presentWhole($c, $fieldLabel);
        }

        return $this->presentWhole($c, $fieldLabel.' · '.implode('.', $tail));
    }

    /** @param list<string> $tail */
    private function presentDurationSeries(FieldChange $c, string $fieldLabel, array $tail): ?PresentedChange
    {
        if ([] === $tail) {
            return $this->presentWhole($c, $fieldLabel);
        }

        return $this->presentPoint($c, $fieldLabel, $tail[0], array_slice($tail, 1));
    }

    /** @param list<string> $tail */
    private function presentRecoatingTree(FieldChange $c, string $fieldLabel, array $tail): ?PresentedChange
    {
        if ([] === $tail) {
            return $this->presentWhole($c, $fieldLabel);
        }

        if ('default' === $tail[0]) {
            return $this->presentPoint($c, $fieldLabel, $tail[1] ?? '', array_slice($tail, 2));
        }

        if ('children' === $tail[0]) {
            $key = $tail[1] ?? '';
            $label = sprintf('%s над слоем «%s»', $fieldLabel, $key);

            // tail[2] — сегмент 'default' узла-ребёнка (RecoatingIntervalTree::jsonSerialize),
            // отбрасывается так же, как и у корня дерева.
            return $this->presentPoint($c, $label, $tail[3] ?? '', array_slice($tail, 4));
        }

        return null; // неизвестная структура пути внутри дерева — скрыть, а не падать
    }

    /** @param list<string> $tail */
    private function presentDft(FieldChange $c, string $fieldLabel, array $tail): PresentedChange
    {
        if ([] === $tail) {
            return $this->presentWhole($c, $fieldLabel);
        }

        $label = $fieldLabel.' · '.$this->dftSubkeyLabel($tail[0]);

        return $this->presentWhole($c, $label);
    }

    /** @param list<string> $tail */
    private function presentThermal(FieldChange $c, string $fieldLabel, array $tail): PresentedChange
    {
        if ([] === $tail) {
            return $this->presentWhole($c, $fieldLabel);
        }

        $subkey = $tail[0];
        $label = $fieldLabel.' · '.$this->thermalSubkeyLabel($subkey);
        $renderer = 'peak_duration_minutes' === $subkey
            ? fn (mixed $v): string => $this->duration->format($this->toMinutes($v))
            : fn (mixed $v): string => $this->humanizer->humanize($v);

        [$old, $new] = $this->renderValues($c, $renderer);

        return new PresentedChange($c->op, $label, $old, $new);
    }

    /** @param list<string> $tail */
    private function presentMixing(FieldChange $c, string $fieldLabel, array $tail): PresentedChange
    {
        if ([] === $tail) {
            return $this->presentWhole($c, $fieldLabel);
        }

        $label = $fieldLabel.' · '.('mass' === $tail[0] ? 'по массе' : 'по объёму');
        [$old, $new] = $this->renderValues($c, fn (mixed $v): string => $this->mixingValue($v));

        return new PresentedChange($c->op, $label, $old, $new);
    }

    /** Целое значение поля (tail пуст) — рендерится через ValueHumanizer. */
    private function presentWhole(FieldChange $c, string $label): PresentedChange
    {
        [$old, $new] = $this->renderValues($c, fn (mixed $v): string => $this->humanizer->humanize($v));

        return new PresentedChange($c->op, $label, $old, $new);
    }

    /**
     * Одна точка серии длительности (DurationSeries/RecoatingTree после отбрасывания
     * служебных сегментов 'default'/'children.<key>.default'): $temp — температура,
     * $pointTail — то, что осталось ПОСЛЕ температуры (`[]` — целая точка добавлена/
     * удалена, `['time_in_minutes']` — правка длительности, `['is_calculated']` — скрыть).
     *
     * @param list<string> $pointTail
     */
    private function presentPoint(FieldChange $c, string $pointLabel, string $temp, array $pointTail): ?PresentedChange
    {
        if (['is_calculated'] === $pointTail) {
            return null;
        }

        $label = sprintf('%s, %d °C', $pointLabel, (int) $temp);
        $renderer = ['time_in_minutes'] === $pointTail
            ? fn (mixed $v): string => $this->duration->format($this->toMinutes($v))
            : fn (mixed $v): string => $this->duration->format($this->pointMinutes($v));

        [$old, $new] = $this->renderValues($c, $renderer);

        return new PresentedChange($c->op, $label, $old, $new);
    }

    /** @return array{0: string, 1: string} [oldText, newText]; add → oldText='', remove → newText=''. */
    private function renderValues(FieldChange $c, callable $render): array
    {
        return match ($c->op) {
            ChangeOp::Set => [$render($c->old), $render($c->new)],
            ChangeOp::Add => ['', $render($c->new)],
            ChangeOp::Remove => [$render($c->old), ''],
        };
    }

    private function toMinutes(mixed $v): ?int
    {
        return null === $v ? null : (int) $v;
    }

    /** Извлекает time_in_minutes из целой точки `{temperature_at,time_in_minutes,is_calculated}`. */
    private function pointMinutes(mixed $point): ?int
    {
        if (!is_array($point) || !array_key_exists('time_in_minutes', $point)) {
            return null;
        }

        return $this->toMinutes($point['time_in_minutes']);
    }

    private function mixingValue(mixed $v): string
    {
        if (is_array($v) && array_is_list($v) && [] !== $v) {
            return implode(':', $v);
        }

        return $this->humanizer->humanize($v);
    }

    private function dftSubkeyLabel(string $subkey): string
    {
        return match ($subkey) {
            'min' => 'мин.',
            'max' => 'макс.',
            'tds_dft' => 'целевая',
            'type' => 'единица',
            default => $subkey,
        };
    }

    private function thermalSubkeyLabel(string $subkey): string
    {
        return match ($subkey) {
            'continuous_min' => 'непрерывно, мин.',
            'continuous_max' => 'непрерывно, макс.',
            'peak_max' => 'пик',
            'peak_duration_minutes' => 'длительность пика',
            default => $subkey,
        };
    }
}
