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

        $params = [
            'search' => $this->str($r->get('search')),
            'substrates' => $this->strList($r->all('substrates')),
            'environment' => $this->str($r->get('environment')),
            'standard' => $this->str($r->get('standard')),
            'category' => $this->str($r->get('category')),
            'durability' => $this->str($r->get('durability')),
            'substanceIds' => $this->strList($r->all('substanceIds')),
            'substanceTemperature' => $this->int($r->get('substanceTemp')),
            'thermalTemperature' => $this->int($r->get('thermalTemperature')),
            'thermalEnvironment' => $this->str($r->get('thermalEnvironment')),
            'thermalIncludingPeak' => $r->getBoolean('thermalIncludingPeak'),
            'applicationMinTempFrom' => $this->int($r->get('applicationMinTempFrom')),
            'applicationMinTempTo' => $this->int($r->get('applicationMinTempTo')),
            'minApplicationTimeFromMinutes' => $this->hoursToMinutes($r->get('minApplicationTimeFromHours')),
            'minApplicationTimeToMinutes' => $this->hoursToMinutes($r->get('minApplicationTimeToHours')),
        ];

        return $this->dropDependentAxesWithoutParent($params);
    }

    /**
     * Форма-специфика: ось без родителя = не задана. Категория/долговечность без стандарта и
     * тепловая среда/пик без температуры — зануляем (пустой ввод = нет оси). Это интерпретация
     * формы, не бизнес-правило — потому отдельным методом, а не внутри общего shape-маппинга.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function dropDependentAxesWithoutParent(array $params): array
    {
        if (null === $params['standard']) {
            $params['category'] = null;
            $params['durability'] = null;
        }
        if (null === $params['thermalTemperature']) {
            $params['thermalEnvironment'] = null;
            $params['thermalIncludingPeak'] = false;
        }

        return $params;
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
