<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Mapper;

use Symfony\Component\HttpFoundation\Request;

/**
 * Чистый shape-маппинг формы правки → плоский снимок CoatingSystemsFilterInput (та же непрозрачная
 * форма, что хранит запрос). Время нанесения форма даёт в часах — переводим в минуты (канон домена).
 * Зависимые оси зачищаем: категория/долговечность без стандарта и тепловая среда/пик без температуры —
 * это не бизнес-правило, а форма-специфика (пустой ввод = нет оси), поэтому живёт в маппере.
 */
final class InquiryReprocessParametersMapper
{
    private const MINUTES_PER_HOUR = 60;

    /**
     * @return array<string, mixed>
     */
    public function parametersFromRequest(Request $request): array
    {
        $r = $request->request;

        $standard = $this->str($r->get('standard'));
        $thermalTemperature = $this->int($r->get('thermalTemperature'));

        return [
            'search' => $this->str($r->get('search')),
            'substrates' => $this->strList($r->all('substrates')),
            'environment' => $this->str($r->get('environment')),
            'standard' => $standard,
            'category' => null !== $standard ? $this->str($r->get('category')) : null,
            'durability' => null !== $standard ? $this->str($r->get('durability')) : null,
            'substanceIds' => $this->strList($r->all('substanceIds')),
            'substanceTemperature' => $this->int($r->get('substanceTemp')),
            'thermalTemperature' => $thermalTemperature,
            'thermalEnvironment' => null !== $thermalTemperature ? $this->str($r->get('thermalEnvironment')) : null,
            'thermalIncludingPeak' => null !== $thermalTemperature && $r->getBoolean('thermalIncludingPeak'),
            'applicationMinTempFrom' => $this->int($r->get('applicationMinTempFrom')),
            'applicationMinTempTo' => $this->int($r->get('applicationMinTempTo')),
            'minApplicationTimeFromMinutes' => $this->hoursToMinutes($r->get('minApplicationTimeFromHours')),
            'minApplicationTimeToMinutes' => $this->hoursToMinutes($r->get('minApplicationTimeToHours')),
        ];
    }

    private function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * @param array<int|string, mixed> $values
     *
     * @return list<string>
     */
    private function strList(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            $s = $this->str($v);
            if (null !== $s) {
                $out[] = $s;
            }
        }

        return $out;
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function hoursToMinutes(mixed $value): ?int
    {
        $hours = $this->int($value);

        return null !== $hours ? $hours * self::MINUTES_PER_HOUR : null;
    }
}
