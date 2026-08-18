<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Aggregate\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\Coating\DftRange;
use App\Coatings\Domain\Aggregate\Coating\DryingTimeSeries;
use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\Coating\Gloss;
use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\Specification\UniqueTitleCoatingSpecification;
use App\Coatings\Domain\Aggregate\Coating\TimeAtTemperature;
use App\Coatings\Domain\Aggregate\Color\Color;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Event\CoatingMutated;
use App\Shared\Domain\Aggregate\Enum\ThicknessType;
use App\Shared\Domain\Aggregate\ValueObject\PositiveNumberRange;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CoatingTest extends TestCase
{
    public function test_min_recoating_for_falls_back_to_root_default_when_no_branches(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        // Плоское дерево: любой топкоат/среда → корневой default. tdsDft=100, actualDft=null → без пересчёта.
        $this->assertSame(60, $coating->minRecoatingFor(CoatingBase::EP, EnvironmentType::Atmospheric));
    }

    public function test_min_recoating_for_returns_topcoat_leaf_when_present(): void
    {
        $min = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            'default',
            new RecoatingIntervalTree(
                new DryingTimeSeries(new TimeAtTemperature(20, 30)),
                'atmospheric',
                new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 15)), 'EP'),
            ),
        );
        $coating = $this->makeCoating(min: $min, max: null);

        // Спускаемся до листа основания топкоата (EP = 15), а не берём дефолт среды/корня.
        $this->assertSame(15, $coating->minRecoatingFor(CoatingBase::EP, EnvironmentType::Atmospheric));
    }

    public function test_min_recoating_for_uses_env_default_when_topcoat_missing(): void
    {
        $min = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            'default',
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 30)), 'atmospheric'),
        );
        $coating = $this->makeCoating(min: $min, max: null);

        // PUR-ветки под atmospheric нет → дефолт среды (30), не корневой (60).
        $this->assertSame(30, $coating->minRecoatingFor(CoatingBase::PUR, EnvironmentType::Atmospheric));
    }

    public function test_min_recoating_for_interpolates_by_temperature(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(
                new TimeAtTemperature(20, 30),
                new TimeAtTemperature(30, 15),
            )),
            max: null,
        );

        // 25 °C между точками 20→30 и 30→15: линейно 22.5 → round 23.
        $this->assertSame(
            23,
            $coating->minRecoatingFor(CoatingBase::EP, EnvironmentType::Atmospheric, temperature: 25),
        );
    }

    public function test_min_recoating_for_scales_by_actual_thickness(): void
    {
        // tdsDft=100, серия при 20 °C = 240 мин, модель LINEAR по умолчанию.
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 240))),
            max: null,
        );

        // actualDft=null → эталон tdsDft, без пересчёта.
        $this->assertSame(240, $coating->minRecoatingFor(CoatingBase::EP, EnvironmentType::Atmospheric));
        // 240 * 150/100 = 360.
        $this->assertSame(360, $coating->minRecoatingFor(CoatingBase::EP, EnvironmentType::Atmospheric, actualDft: 150));
        // 240 * 80/100 = 192.
        $this->assertSame(192, $coating->minRecoatingFor(CoatingBase::EP, EnvironmentType::Atmospheric, actualDft: 80));
    }

    public function test_min_recoating_for_returns_null_when_temperature_out_of_series_range(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        // Единственная точка серии при 20 °C; для −10 °C данных нет → null.
        $this->assertNull(
            $coating->minRecoatingFor(CoatingBase::EP, EnvironmentType::Atmospheric, temperature: -10),
        );
    }

    public function test_max_recoating_for_returns_null_when_max_is_absent(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $this->assertNull(
            $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP),
        );
    }

    public function test_max_recoating_for_uses_env_default_when_topcoat_missing(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef = new DryingTimeSeries(new TimeAtTemperature(20, 7 * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree($atmDef, 'atmospheric'),
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $series = $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP);

        $this->assertSame($atmDef, $series, 'EP не задан → возвращаем дефолт среды');
    }

    public function test_max_recoating_for_returns_topcoat_leaf_when_present(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef = new DryingTimeSeries(new TimeAtTemperature(20, 7 * 24 * 60));
        $epDef = new DryingTimeSeries(new TimeAtTemperature(20, 30 * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree(
                $atmDef,
                'atmospheric',
                new RecoatingIntervalTree($epDef, 'EP'),
            ),
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $this->assertSame(
            $epDef,
            $coating->maxRecoatingFor(EnvironmentType::Atmospheric, CoatingBase::EP),
        );
    }

    public function test_max_recoating_for_falls_back_to_root_when_env_missing(): void
    {
        $rootDef = new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60));
        $atmDef = new DryingTimeSeries(new TimeAtTemperature(20, 7 * 24 * 60));
        $max = new RecoatingIntervalTree(
            $rootDef,
            'default',
            new RecoatingIntervalTree($atmDef, 'atmospheric'),
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $this->assertSame(
            $rootDef,
            $coating->maxRecoatingFor(EnvironmentType::Special, CoatingBase::EP),
            'Special-ветки нет → корневой default',
        );
    }

    public function test_max_recoating_point_at_applies_get_point_to_found_series(): void
    {
        $epSeries = new DryingTimeSeries(
            new TimeAtTemperature(20, 30 * 24 * 60),
            new TimeAtTemperature(30, 15 * 24 * 60),
        );
        $max = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 14 * 24 * 60)),
            'default',
            new RecoatingIntervalTree(
                new DryingTimeSeries(new TimeAtTemperature(20, 7 * 24 * 60)),
                'atmospheric',
                new RecoatingIntervalTree($epSeries, 'EP'),
            ),
        );
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: $max,
        );

        $point = $coating->maxRecoatingPointAt(EnvironmentType::Atmospheric, CoatingBase::EP, 20);
        $this->assertNotNull($point);
        $this->assertSame(30 * 24 * 60, $point->timeInMinutes);
    }

    public function test_max_recoating_point_at_returns_null_when_max_is_absent(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $this->assertNull(
            $coating->maxRecoatingPointAt(EnvironmentType::Atmospheric, CoatingBase::EP, 20),
        );
    }

    public function test_defaults_drying_max_temp_to50(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $this->assertSame(50, $coating->getDryingMaxTemp());
    }

    public function test_rejects_application_min_greater_or_equal_to_drying_max(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/строго меньше/');
        $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
            applicationMinTemp: 60,
            dryingMaxTemp: 50,
        );
    }

    public function test_rejects_dry_to_touch_point_below_application_min(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/вне допустимого диапазона/');
        $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
            applicationMinTemp: 10,
            dryingMaxTemp: 50,
            dryToTouch: new DryingTimeSeries(new TimeAtTemperature(5, 60)), // 5 < 10
        );
    }

    public function test_rejects_recoating_tree_point_above_drying_max(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/вне допустимого диапазона/');
        $this->makeCoating(
            // 20°C — обязательная base-точка (инвариант); 80°C > 50 dryingMax → падает validateTemperatureRange.
            min: new RecoatingIntervalTree(new DryingTimeSeries(
                new TimeAtTemperature(20, 240),
                new TimeAtTemperature(80, 60),
            )),
            max: null,
            applicationMinTemp: 5,
            dryingMaxTemp: 50,
        );
    }

    public function test_widening_range_before_adding_higher_point_succeeds(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(
                new TimeAtTemperature(20, 240),
                new TimeAtTemperature(50, 60),
            )),
            max: null,
            applicationMinTemp: 5,
            dryingMaxTemp: 50,
        );

        // Сценарий: пользователь расширяет диапазон ДО того как добавить
        // более горячие точки. Это ключевое для UpdateCoatingCommandHandler
        // — temperature-границы должны устанавливаться раньше series-сеттеров.
        $coating->setDryingMaxTemp(80);
        $coating->setMinRecoatingInterval(
            new RecoatingIntervalTree(new DryingTimeSeries(
                new TimeAtTemperature(20, 240),
                new TimeAtTemperature(75, 60),
            )),
        );

        $this->assertSame(80, $coating->getDryingMaxTemp());
    }

    public function test_adding_higher_point_before_widening_range_throws(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(
                new TimeAtTemperature(20, 240),
                new TimeAtTemperature(50, 60),
            )),
            max: null,
            applicationMinTemp: 5,
            dryingMaxTemp: 50,
        );

        // Обратный порядок ломается — это документация для будущих рефакторов
        // UpdateCoatingCommandHandler: НЕ ставить series раньше temperature-границ.
        $this->expectException(AppException::class);
        $coating->setMinRecoatingInterval(
            new RecoatingIntervalTree(new DryingTimeSeries(
                new TimeAtTemperature(20, 240),
                new TimeAtTemperature(75, 60),
            )),
        );
    }

    public function test_rejects_recoating_nested_branch_point_outside_range(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/вне допустимого диапазона/');
        $childBranch = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(70, 60)), // вложенная точка > 50
            'atmospheric',
        );
        $tree = new RecoatingIntervalTree(
            new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            'default',
            $childBranch,
        );
        $this->makeCoating(min: $tree, max: null, applicationMinTemp: 5, dryingMaxTemp: 50);
    }

    public function test_min_recoating_requires_base_point_at_20(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/точка минимального интервала перекрытия при \+20 °C/u');
        $this->makeCoating(
            // Только 30 °C — база при 20 °C отсутствует, интерполировать нельзя.
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(30, 60))),
            max: null,
            applicationMinTemp: 5,
            dryingMaxTemp: 50,
        );
    }

    public function test_min_recoating_at_20_must_have_positive_time(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/должна иметь положительное время/u');
        $this->makeCoating(
            // 20 °C с unknown-длительностью (null) — не позволяет считать интервал.
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, null))),
            max: null,
            applicationMinTemp: 5,
            dryingMaxTemp: 50,
        );
    }

    public function test_min_recoating_at_20_rejects_unlimited(): void
    {
        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/должна иметь положительное время/u');
        $this->makeCoating(
            // 20 °C с 0 = unlimited — тоже не годится, нужна конкретная длительность.
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 0))),
            max: null,
            applicationMinTemp: 5,
            dryingMaxTemp: 50,
        );
    }

    public function test_max_recoating_does_not_require_20c_base_point(): void
    {
        // Инвариант применяется только к min-tree; max — свободный.
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(30, 60))),
        );
        $this->assertNotNull($coating->getMaxRecoatingInterval());
    }

    public function test_is_zinc_rich_defaults_to_false(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        self::assertFalse($coating->isZincRich());
    }

    public function test_is_zinc_rich_can_be_toggled(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $coating->setIsZincRich(true);
        self::assertTrue($coating->isZincRich());
        $coating->setIsZincRich(false);
        self::assertFalse($coating->isZincRich());
    }

    public function test_set_title_raises_coating_mutated(): void
    {
        $c = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $c->pullEvents();
        $c->setTitle('New Title');
        $events = $c->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingMutated::class, $events[0]);
        self::assertSame($c->getId(), $events[0]->coatingId);
    }

    public function test_set_description_raises_coating_mutated(): void
    {
        $c = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $c->pullEvents();
        $c->setDescription('New description');
        $events = $c->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingMutated::class, $events[0]);
        self::assertSame($c->getId(), $events[0]->coatingId);
    }

    public function test_set_base_raises_coating_mutated(): void
    {
        $c = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $c->pullEvents();
        $c->setBase(CoatingBase::PUR);
        $events = $c->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingMutated::class, $events[0]);
        self::assertSame($c->getId(), $events[0]->coatingId);
    }

    public function test_set_is_zinc_rich_raises_coating_mutated(): void
    {
        $c = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $c->pullEvents();
        $c->setIsZincRich(true);
        $events = $c->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingMutated::class, $events[0]);
        self::assertSame($c->getId(), $events[0]->coatingId);
    }

    public function test_set_application_min_temp_raises_coating_mutated(): void
    {
        $c = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
            applicationMinTemp: 5,
            dryingMaxTemp: 50,
        );
        $c->pullEvents();
        $c->setApplicationMinTemp(10);
        $events = $c->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingMutated::class, $events[0]);
        self::assertSame($c->getId(), $events[0]->coatingId);
    }

    public function test_set_dft_range_raises_coating_mutated(): void
    {
        $c = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $c->pullEvents();
        $c->setDftRange(new DftRange(new PositiveNumberRange(120, 180), 150, ThicknessType::MIC));
        $events = $c->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingMutated::class, $events[0]);
        self::assertSame($c->getId(), $events[0]->coatingId);
    }

    public function test_set_min_recoating_interval_raises_coating_mutated(): void
    {
        $c = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $c->pullEvents();
        $c->setMinRecoatingInterval(
            new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 120)))
        );
        $events = $c->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingMutated::class, $events[0]);
        self::assertSame($c->getId(), $events[0]->coatingId);
    }

    public function test_non_tintable_requires_at_least_one_color(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $this->expectException(AppException::class);
        $this->expectExceptionMessageMatches('/хотя бы один возможный цвет/u');
        $coating->applyColorScheme(false);
    }

    public function test_tintable_allows_empty_color_list(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $coating->applyColorScheme(true);

        self::assertTrue($coating->isTintable());
        self::assertCount(0, $coating->getPossibleColors());
    }

    public function test_non_tintable_with_colors_is_accepted(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        $coating->applyColorScheme(false, $this->color());

        self::assertFalse($coating->isTintable());
        self::assertCount(1, $coating->getPossibleColors());
    }

    public function test_apply_color_scheme_raises_coating_mutated(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );
        $coating->pullEvents();

        $coating->applyColorScheme(true);

        $events = $coating->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(CoatingMutated::class, $events[0]);
    }

    public function test_gloss_defaults_to_null_and_can_be_set(): void
    {
        $coating = $this->makeCoating(
            min: new RecoatingIntervalTree(new DryingTimeSeries(new TimeAtTemperature(20, 60))),
            max: null,
        );

        self::assertNull($coating->getGloss());
        $coating->setGloss(Gloss::MATTE);
        self::assertSame(Gloss::MATTE, $coating->getGloss());
    }

    private function color(): Color
    {
        return new Color(Uuid::v4(), 'Серый', null, '#9DA3A6');
    }

    private function makeCoating(
        RecoatingIntervalTree $min,
        ?RecoatingIntervalTree $max,
        int $applicationMinTemp = 5,
        int $dryingMaxTemp = 50,
        ?DryingTimeSeries $dryToTouch = null,
        ?DryingTimeSeries $fullCure = null,
    ): Coating {
        $manufacturer = $this->createMock(Manufacturer::class);
        $manufacturer->method('getId')->willReturn('00000000-0000-0000-0000-000000000001');

        $spec = new CoatingSpecification(
            $this->createMock(UniqueTitleCoatingSpecification::class),
        );

        return new Coating(
            UuidService::generateUuid(),
            'Test Coating',
            'desc',
            50,
            1.2,
            CoatingBase::EP,
            new DftRange(new PositiveNumberRange(80, 150), 100, ThicknessType::MIC),
            $applicationMinTemp,
            $dryToTouch ?? new DryingTimeSeries(new TimeAtTemperature(20, 60)),
            $fullCure ?? new DryingTimeSeries(new TimeAtTemperature(20, 24 * 60)),
            $min,
            $max,
            1.0,
            null,
            $manufacturer,
            $spec,
            $dryingMaxTemp,
        );
    }
}
