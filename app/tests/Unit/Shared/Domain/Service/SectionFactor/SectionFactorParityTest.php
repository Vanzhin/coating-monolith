<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain\Service\SectionFactor;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Domain\Service\SectionFactor\AngleSection;
use App\Shared\Domain\Service\SectionFactor\ChannelSection;
use App\Shared\Domain\Service\SectionFactor\HeatingScheme;
use App\Shared\Domain\Service\SectionFactor\IBeamSection;
use App\Shared\Domain\Service\SectionFactor\PipeSection;
use App\Shared\Domain\Service\SectionFactor\ProfileSection;
use App\Shared\Domain\Service\SectionFactor\RectHollowSection;
use App\Shared\Domain\Service\SectionFactor\RoundBarSection;
use App\Shared\Domain\Service\SectionFactor\SheetSection;
use App\Shared\Domain\Service\SectionFactorCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Паритет фронта и бэка: калькулятор ПТМ считает в браузере (JS section_factor_geometry.mjs) ради офлайна,
 * но формулы обязаны совпадать с доменом (источник истины). Матрица «тип × размеры × схема обогрева» гоняется
 * через PHP-домен и через тот же JS-модуль (node), результаты сверяются. Разойдётся формула в одном из зеркал —
 * тест красный. node берём из сборки ассетов; где его нет (голый PHP-контейнер) — тест скипается, а не падает.
 */
final class SectionFactorParityTest extends TestCase
{
    /** Абсолютно-относительный допуск: ловит дрейф формулы (разница в целые мм), терпит ULP-шум trig PHP vs V8. */
    private const EPS_FACTOR = 1e-9;
    private const EPS_FLOOR = 1e-6;

    public function test_js_mirror_matches_php_domain(): void
    {
        $node = $this->findNode();
        if (null === $node) {
            self::markTestSkipped('node недоступен — паритет PHP↔JS не проверяется (ожидается в CI со сборкой ассетов)');
        }

        $cases = $this->matrix();
        $calc = new SectionFactorCalculator();

        // ожидаемое — из домена
        $expected = [];
        foreach ($cases as $i => $case) {
            $section = $this->makeSection($case['type'], $case['dims']);
            $scheme = $this->makeScheme($case['scheme']);
            $area = $section->crossSectionArea();
            $perimeter = $section->heatedPerimeter($scheme);
            $spm = $calc->surfaceAreaPerMeter(new PositiveNumber($perimeter));
            $expected[$i] = [
                'area' => $area,
                'perimeter' => $perimeter,
                'ptm' => $calc->reducedThickness(new PositiveNumber($area), new PositiveNumber($perimeter)),
                'spm' => $spm,
                'mass' => $calc->massPerMeter(new PositiveNumber($area)),
                'spt' => $calc->surfaceAreaPerTon(new PositiveNumber($area), $spm),
            ];
        }

        $actual = $this->runJs($node, $cases);
        self::assertCount(count($cases), $actual, 'JS вернул не столько результатов, сколько кейсов');

        foreach ($cases as $i => $case) {
            $label = sprintf('%s [%s] %s', $case['type'], implode(',', array_keys(array_filter($case['scheme']))), json_encode($case['dims']));
            self::assertIsArray($actual[$i], "JS вернул null для кейса: $label");
            foreach ($expected[$i] as $field => $exp) {
                $act = $actual[$i][$field];
                $eps = self::EPS_FLOOR + self::EPS_FACTOR * abs($exp);
                self::assertEqualsWithDelta($exp, $act, $eps, "Расхождение PHP↔JS в '$field' для $label");
            }
        }
    }

    /** @return array<int, array{type: string, dims: array<string, float>, scheme: array{top: bool, bottom: bool, left: bool, right: bool}}> */
    private function matrix(): array
    {
        $full = ['top' => true, 'bottom' => true, 'left' => true, 'right' => true];
        $three = ['top' => false, 'bottom' => true, 'left' => true, 'right' => true];
        $sides = ['top' => false, 'bottom' => false, 'left' => true, 'right' => true];
        $topOnly = ['top' => true, 'bottom' => false, 'left' => false, 'right' => false];
        $leftOnly = ['top' => false, 'bottom' => false, 'left' => true, 'right' => false];
        $rightOnly = ['top' => false, 'bottom' => false, 'left' => false, 'right' => true];
        $againstWall = ['top' => true, 'bottom' => false, 'left' => false, 'right' => true];
        $bothFaces = ['top' => true, 'bottom' => true, 'left' => false, 'right' => false];

        $cases = [];
        $add = function (string $type, array $dims, array $schemes) use (&$cases): void {
            foreach ($schemes as $scheme) {
                $cases[] = ['type' => $type, 'dims' => $dims, 'scheme' => $scheme];
            }
        };

        // Двутавр: со скруглением (10Б1) и без.
        $add('i_beam', ['height' => 100, 'flangeWidth' => 55, 'webThickness' => 4.1, 'flangeThickness' => 5.7, 'filletRadius' => 7], [$full, $three, $sides, $topOnly]);
        $add('i_beam', ['height' => 200, 'flangeWidth' => 100, 'webThickness' => 6, 'flangeThickness' => 8, 'filletRadius' => 0], [$full]);
        // Швеллер: с уклоном полок и радиусами, и без уклона.
        $add('channel', ['height' => 100, 'flangeWidth' => 46, 'webThickness' => 4.5, 'flangeThickness' => 7.6, 'innerRadius' => 7, 'outerRadius' => 3, 'slope' => 0.1], [$full, $three, $leftOnly, $rightOnly]);
        $add('channel', ['height' => 180, 'flangeWidth' => 70, 'webThickness' => 5.1, 'flangeThickness' => 8.7, 'innerRadius' => 9, 'outerRadius' => 0, 'slope' => 0], [$full]);
        // Уголок: равнополочный со скруглениями и неравнополочный без.
        $add('angle', ['legA' => 100, 'legB' => 100, 'thickness' => 8, 'outerRadius' => 12, 'innerRadius' => 4], [$full, $againstWall, $rightOnly, $leftOnly]);
        $add('angle', ['legA' => 125, 'legB' => 80, 'thickness' => 10, 'outerRadius' => 0, 'innerRadius' => 0], [$full, $leftOnly, $topOnly]);
        // Труба / круг — периметр по контуру, схема не влияет.
        $add('pipe', ['outerDiameter' => 108, 'wallThickness' => 4], [$full]);
        $add('round_bar', ['diameter' => 40], [$full]);
        // Профиль замкнутый: с наружным радиусом и без.
        $add('rect_hollow', ['height' => 100, 'width' => 100, 'wallThickness' => 5, 'outerRadius' => 8], [$full, $three, $sides]);
        $add('rect_hollow', ['height' => 120, 'width' => 60, 'wallThickness' => 4, 'outerRadius' => 0], [$full]);
        // Лист: две плоскости и одна.
        $add('sheet', ['thickness' => 10], [$bothFaces, $topOnly]);

        return $cases;
    }

    /** @param array<string, float> $d */
    private function makeSection(string $type, array $d): ProfileSection
    {
        return match ($type) {
            'i_beam' => new IBeamSection($d['height'], $d['flangeWidth'], $d['webThickness'], $d['flangeThickness'], $d['filletRadius'] ?? 0.0),
            'channel' => new ChannelSection($d['height'], $d['flangeWidth'], $d['webThickness'], $d['flangeThickness'], $d['innerRadius'] ?? 0.0, $d['outerRadius'] ?? 0.0, $d['slope'] ?? 0.0),
            'angle' => new AngleSection($d['legA'], $d['legB'], $d['thickness'], $d['outerRadius'] ?? 0.0, $d['innerRadius'] ?? 0.0),
            'pipe' => new PipeSection($d['outerDiameter'], $d['wallThickness']),
            'round_bar' => new RoundBarSection($d['diameter']),
            'rect_hollow' => new RectHollowSection($d['height'], $d['width'], $d['wallThickness'], $d['outerRadius'] ?? 0.0),
            'sheet' => new SheetSection($d['thickness']),
            default => throw new \LogicException("Неизвестный тип профиля: $type"),
        };
    }

    /** @param array{top: bool, bottom: bool, left: bool, right: bool} $s */
    private function makeScheme(array $s): HeatingScheme
    {
        return new HeatingScheme($s['top'], $s['bottom'], $s['left'], $s['right']);
    }

    /**
     * @param array<int, array{type: string, dims: array<string, float>, scheme: array<string, bool>}> $cases
     *
     * @return array<int, mixed>
     */
    private function runJs(string $node, array $cases): array
    {
        $harness = \dirname(__DIR__, 5).'/js/section_factor_parity_harness.mjs';
        self::assertFileExists($harness, 'node-харнесс паритета не найден');

        $input = tempnam(sys_get_temp_dir(), 'sf_parity_');
        file_put_contents($input, json_encode($cases));
        $cmd = sprintf('%s %s %s 2>&1', escapeshellarg($node), escapeshellarg($harness), escapeshellarg($input));
        $out = shell_exec($cmd);
        unlink($input);

        $decoded = json_decode((string) $out, true);
        self::assertIsArray($decoded, "node-харнесс не вернул JSON. Вывод: $out");

        return $decoded;
    }

    private function findNode(): ?string
    {
        foreach (['node', '/usr/local/bin/node', '/usr/bin/node'] as $candidate) {
            $v = @shell_exec(sprintf('%s --version 2>/dev/null', escapeshellarg($candidate)));
            if (is_string($v) && str_starts_with(trim($v), 'v')) {
                return $candidate;
            }
        }

        return null;
    }
}
