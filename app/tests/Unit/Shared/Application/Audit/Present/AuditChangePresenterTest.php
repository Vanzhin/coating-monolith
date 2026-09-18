<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit\Present;

use App\Shared\Application\Audit\Present\AuditChangePresenter;
use App\Shared\Application\Audit\Present\DurationHumanizer;
use App\Shared\Application\Audit\Present\Formatter\DftRangeFormatter;
use App\Shared\Application\Audit\Present\Formatter\DurationSeriesFormatter;
use App\Shared\Application\Audit\Present\Formatter\MixingRatioFormatter;
use App\Shared\Application\Audit\Present\Formatter\RecoatingTreeFormatter;
use App\Shared\Application\Audit\Present\Formatter\ScalarFormatter;
use App\Shared\Application\Audit\Present\Formatter\ThermalLimitsFormatter;
use App\Shared\Application\Audit\Present\ValueHumanizer;
use App\Shared\Domain\Audit\AuditFieldKind;
use App\Shared\Domain\Audit\ChangeOp;
use App\Shared\Domain\Audit\FieldChange;
use PHPUnit\Framework\TestCase;

final class AuditChangePresenterTest extends TestCase
{
    private AuditChangePresenter $presenter;

    protected function setUp(): void
    {
        $duration = new DurationHumanizer();
        $series = new DurationSeriesFormatter($duration);
        $humanizer = new ValueHumanizer(
            new DftRangeFormatter(),
            $series,
            new RecoatingTreeFormatter($series),
            new ThermalLimitsFormatter($duration),
            new MixingRatioFormatter(),
            new ScalarFormatter(),
        );
        $this->presenter = new AuditChangePresenter($humanizer, $duration);
    }

    public function test_scalar_whole_field(): void
    {
        $c = FieldChange::set('title', 'Старое название', 'Новое название');
        $p = $this->presenter->present($c, 'Название', AuditFieldKind::Scalar);

        self::assertNotNull($p);
        self::assertSame(ChangeOp::Set, $p->op);
        self::assertSame('Название', $p->label);
        self::assertSame('Старое название', $p->oldText);
        self::assertSame('Новое название', $p->newText);
    }

    public function test_scalar_with_unexpected_tail_appends_it_to_the_label(): void
    {
        $c = FieldChange::set('title.sub', 1, 2);
        $p = $this->presenter->present($c, 'Название', AuditFieldKind::Scalar);

        self::assertNotNull($p);
        self::assertSame('Название · sub', $p->label);
        self::assertSame('1', $p->oldText);
        self::assertSame('2', $p->newText);
    }

    public function test_duration_series_whole_field(): void
    {
        $old = [['temperature_at' => 5, 'time_in_minutes' => 960, 'is_calculated' => false]];
        $new = [['temperature_at' => 5, 'time_in_minutes' => 900, 'is_calculated' => false]];
        $c = FieldChange::set('dryToTouch', $old, $new);
        $p = $this->presenter->present($c, 'Высыхание на отлип', AuditFieldKind::DurationSeries);

        self::assertNotNull($p);
        self::assertSame('Высыхание на отлип', $p->label);
        self::assertSame('5 °C — 16 ч', $p->oldText);
        self::assertSame('5 °C — 15 ч', $p->newText);
    }

    public function test_duration_series_point_added(): void
    {
        $point = ['temperature_at' => 10, 'time_in_minutes' => 360, 'is_calculated' => false];
        $c = FieldChange::add('dryToTouch.10', $point);
        $p = $this->presenter->present($c, 'Высыхание на отлип', AuditFieldKind::DurationSeries);

        self::assertNotNull($p);
        self::assertSame(ChangeOp::Add, $p->op);
        self::assertSame('Высыхание на отлип, 10 °C', $p->label);
        self::assertSame('', $p->oldText);
        self::assertSame('6 ч', $p->newText);
    }

    public function test_duration_series_point_time_in_minutes_edited(): void
    {
        $c = FieldChange::set('dryToTouch.5.time_in_minutes', 960, 900);
        $p = $this->presenter->present($c, 'Высыхание на отлип', AuditFieldKind::DurationSeries);

        self::assertNotNull($p);
        self::assertSame('Высыхание на отлип, 5 °C', $p->label);
        self::assertSame('16 ч', $p->oldText);
        self::assertSame('15 ч', $p->newText);
    }

    public function test_duration_series_is_calculated_flag_is_hidden(): void
    {
        $c = FieldChange::set('dryToTouch.5.is_calculated', false, true);
        $p = $this->presenter->present($c, 'Высыхание на отлип', AuditFieldKind::DurationSeries);

        self::assertNull($p);
    }

    public function test_recoating_tree_whole_field(): void
    {
        $old = ['default' => [['temperature_at' => 20, 'time_in_minutes' => 540, 'is_calculated' => false]], 'children' => []];
        $new = ['default' => [['temperature_at' => 20, 'time_in_minutes' => 480, 'is_calculated' => false]], 'children' => []];
        $c = FieldChange::set('minRecoatingInterval', $old, $new);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNotNull($p);
        self::assertSame('Мин. интервал перекрытия', $p->label);
        self::assertSame('20 °C — 9 ч', $p->oldText);
        self::assertSame('20 °C — 8 ч', $p->newText);
    }

    public function test_recoating_tree_default_point_added(): void
    {
        $point = ['temperature_at' => 20, 'time_in_minutes' => 540, 'is_calculated' => false];
        $c = FieldChange::add('minRecoatingInterval.default.20', $point);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNotNull($p);
        self::assertSame(ChangeOp::Add, $p->op);
        self::assertSame('Мин. интервал перекрытия, 20 °C', $p->label);
        self::assertSame('', $p->oldText);
        self::assertSame('9 ч', $p->newText);
    }

    public function test_recoating_tree_default_point_time_in_minutes_edited(): void
    {
        $c = FieldChange::set('minRecoatingInterval.default.20.time_in_minutes', 540, 480);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNotNull($p);
        self::assertSame('Мин. интервал перекрытия, 20 °C', $p->label);
        self::assertSame('9 ч', $p->oldText);
        self::assertSame('8 ч', $p->newText);
    }

    public function test_recoating_tree_child_point_added(): void
    {
        $point = ['temperature_at' => 20, 'time_in_minutes' => 540, 'is_calculated' => false];
        $c = FieldChange::add('minRecoatingInterval.children.topcoat.default.20', $point);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNotNull($p);
        self::assertSame(ChangeOp::Add, $p->op);
        self::assertSame('Мин. интервал перекрытия над слоем «topcoat», 20 °C', $p->label);
        self::assertSame('', $p->oldText);
        self::assertSame('9 ч', $p->newText);
    }

    public function test_recoating_tree_child_point_time_in_minutes_edited(): void
    {
        $c = FieldChange::set('minRecoatingInterval.children.topcoat.default.20.time_in_minutes', 540, 480);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNotNull($p);
        self::assertSame('Мин. интервал перекрытия над слоем «topcoat», 20 °C', $p->label);
        self::assertSame('9 ч', $p->oldText);
        self::assertSame('8 ч', $p->newText);
    }

    public function test_recoating_tree_is_calculated_flag_is_hidden(): void
    {
        $c = FieldChange::set('minRecoatingInterval.children.topcoat.default.20.is_calculated', false, true);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNull($p);
    }

    /**
     * Домен разрешает 3 уровня: root(default) → children по среде эксплуатации →
     * grandchildren по основе последующего ЛКМ (CoatingRecoatingTreeValidator). Путь
     * grandchild-узла: `children.<envKey>.children.<baseKey>.default.<temp>[...]`.
     */
    public function test_recoating_tree_grandchild_point_time_in_minutes_edited(): void
    {
        $c = FieldChange::set('minRecoatingInterval.children.atmospheric.children.ep.default.20.time_in_minutes', 540, 480);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNotNull($p);
        self::assertSame('Мин. интервал перекрытия над слоем «atmospheric / ep», 20 °C', $p->label);
        self::assertSame('9 ч', $p->oldText);
        self::assertSame('8 ч', $p->newText);
    }

    public function test_recoating_tree_grandchild_is_calculated_flag_is_hidden(): void
    {
        $c = FieldChange::set('minRecoatingInterval.children.atmospheric.children.ep.default.20.is_calculated', false, true);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNull($p);
    }

    public function test_recoating_tree_child_whole_node_added(): void
    {
        $node = ['default' => [['temperature_at' => 20, 'time_in_minutes' => 480, 'is_calculated' => false]], 'children' => []];
        $c = FieldChange::add('minRecoatingInterval.children.atmospheric', $node);
        $p = $this->presenter->present($c, 'Мин. интервал перекрытия', AuditFieldKind::RecoatingTree);

        self::assertNotNull($p);
        self::assertSame(ChangeOp::Add, $p->op);
        self::assertSame('Мин. интервал перекрытия над слоем «atmospheric»', $p->label);
        self::assertSame('', $p->oldText);
        self::assertSame('20 °C — 8 ч', $p->newText);
    }

    public function test_dft_whole_field(): void
    {
        $old = ['min' => 80, 'max' => 150, 'tds_dft' => 100, 'type' => 'мкм'];
        $new = ['min' => 90, 'max' => 160, 'tds_dft' => 110, 'type' => 'мкм'];
        $c = FieldChange::set('dftRange', $old, $new);
        $p = $this->presenter->present($c, 'Толщина плёнки (DFT)', AuditFieldKind::Dft);

        self::assertNotNull($p);
        self::assertSame('Толщина плёнки (DFT)', $p->label);
        self::assertSame('80–150 мкм (целевая 100)', $p->oldText);
        self::assertSame('90–160 мкм (целевая 110)', $p->newText);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dftSubkeys(): iterable
    {
        yield 'min' => ['min', 'мин.'];
        yield 'max' => ['max', 'макс.'];
        yield 'tds_dft' => ['tds_dft', 'целевая'];
        yield 'type' => ['type', 'единица'];
    }

    /**
     * @dataProvider dftSubkeys
     */
    public function test_dft_subkey(string $subkey, string $rusLabel): void
    {
        $c = FieldChange::set('dftRange.'.$subkey, 80, 90);
        $p = $this->presenter->present($c, 'Толщина плёнки (DFT)', AuditFieldKind::Dft);

        self::assertNotNull($p);
        self::assertSame('Толщина плёнки (DFT) · '.$rusLabel, $p->label);
        self::assertSame('80', $p->oldText);
        self::assertSame('90', $p->newText);
    }

    public function test_thermal_whole_field(): void
    {
        $old = ['continuous_min' => -30, 'continuous_max' => 120, 'peak_max' => 140, 'peak_duration_minutes' => 90];
        $new = ['continuous_min' => -20, 'continuous_max' => 110, 'peak_max' => 130, 'peak_duration_minutes' => 60];
        $c = FieldChange::set('dryHeatExposure', $old, $new);
        $p = $this->presenter->present($c, 'Сухой нагрев', AuditFieldKind::Thermal);

        self::assertNotNull($p);
        self::assertSame('Сухой нагрев', $p->label);
        self::assertSame("непрерывно \u{2212}30…+120 °C; пик +140 °C до 1 ч 30 мин", $p->oldText);
        self::assertSame("непрерывно \u{2212}20…+110 °C; пик +130 °C до 1 ч", $p->newText);
    }

    public function test_thermal_subkey_plain_value(): void
    {
        $c = FieldChange::set('dryHeatExposure.peak_max', 140, 150);
        $p = $this->presenter->present($c, 'Сухой нагрев', AuditFieldKind::Thermal);

        self::assertNotNull($p);
        self::assertSame('Сухой нагрев · пик', $p->label);
        self::assertSame('140', $p->oldText);
        self::assertSame('150', $p->newText);
    }

    public function test_thermal_subkey_duration_value(): void
    {
        $c = FieldChange::set('dryHeatExposure.peak_duration_minutes', 90, 60);
        $p = $this->presenter->present($c, 'Сухой нагрев', AuditFieldKind::Thermal);

        self::assertNotNull($p);
        self::assertSame('Сухой нагрев · длительность пика', $p->label);
        self::assertSame('1 ч 30 мин', $p->oldText);
        self::assertSame('1 ч', $p->newText);
    }

    public function test_mixing_whole_field(): void
    {
        $old = ['volume' => [1, 4], 'mass' => [1, 5]];
        $new = ['volume' => [1, 5], 'mass' => [1, 6]];
        $c = FieldChange::set('mixingRatio', $old, $new);
        $p = $this->presenter->present($c, 'Пропорция смешивания', AuditFieldKind::Mixing);

        self::assertNotNull($p);
        self::assertSame('Пропорция смешивания', $p->label);
        self::assertSame('по объёму 1:4; по массе 1:5', $p->oldText);
        self::assertSame('по объёму 1:5; по массе 1:6', $p->newText);
    }

    public function test_mixing_subkey_with_ratio_list(): void
    {
        $c = FieldChange::set('mixingRatio.volume', [1, 4], [1, 5]);
        $p = $this->presenter->present($c, 'Пропорция смешивания', AuditFieldKind::Mixing);

        self::assertNotNull($p);
        self::assertSame('Пропорция смешивания · по объёму', $p->label);
        self::assertSame('1:4', $p->oldText);
        self::assertSame('1:5', $p->newText);
    }

    public function test_mixing_subkey_mass_label_and_non_list_value_falls_back_to_humanizer(): void
    {
        $c = FieldChange::remove('mixingRatio.mass', null);
        $p = $this->presenter->present($c, 'Пропорция смешивания', AuditFieldKind::Mixing);

        self::assertNotNull($p);
        self::assertSame(ChangeOp::Remove, $p->op);
        self::assertSame('Пропорция смешивания · по массе', $p->label);
        self::assertSame('—', $p->oldText);
        self::assertSame('', $p->newText);
    }
}
