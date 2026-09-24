<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit;

use App\Shared\Application\Audit\AuditLogTransformer;
use App\Shared\Application\Audit\Present\AuditChangePresenter;
use App\Shared\Application\Audit\Present\DurationHumanizer;
use App\Shared\Application\Audit\Present\Formatter\DftRangeFormatter;
use App\Shared\Application\Audit\Present\Formatter\DurationSeriesFormatter;
use App\Shared\Application\Audit\Present\Formatter\MixingRatioFormatter;
use App\Shared\Application\Audit\Present\Formatter\RecoatingTreeFormatter;
use App\Shared\Application\Audit\Present\Formatter\ScalarFormatter;
use App\Shared\Application\Audit\Present\Formatter\ThermalLimitsFormatter;
use App\Shared\Application\Audit\Present\ValueHumanizer;
use App\Shared\Domain\Audit\ActorResolverInterface;
use App\Shared\Domain\Audit\AuditAction;
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditFieldKind;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\ChangeOp;
use App\Shared\Domain\Audit\ChangeSet;
use App\Shared\Domain\Audit\FieldChange;
use PHPUnit\Framework\TestCase;

final class AuditLogTransformerTest extends TestCase
{
    /**
     * @param array<string, string>         $labels
     * @param array<string, AuditFieldKind> $kinds
     */
    private function policy(array $labels, array $kinds = []): AuditPolicyInterface
    {
        return new class($labels, $kinds) implements AuditPolicyInterface {
            /**
             * @param array<string, string>         $labels
             * @param array<string, AuditFieldKind> $kinds
             */
            public function __construct(private array $labels, private array $kinds)
            {
            }

            public function trackedFields(string $entityClass): array
            {
                return $this->labels;
            }

            public function fieldKinds(string $entityClass): array
            {
                return $this->kinds;
            }

            public function invalidate(string $entityClass): void
            {
            }
        };
    }

    /** Стаб резолвера: «system» → «Система», известный ulid → email, иначе — сам id. */
    private function actorResolver(): ActorResolverInterface
    {
        return new class implements ActorResolverInterface {
            public function resolve(string $actorId): string
            {
                return match ($actorId) {
                    'system' => 'Система',
                    'ulid-known' => 'known@example.com',
                    default => $actorId,
                };
            }
        };
    }

    /** Тот же собранный форматер-конвейер, что и в AuditChangePresenterTest — реальный, не мок. */
    private function presenter(): AuditChangePresenter
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

        return new AuditChangePresenter($humanizer, $duration);
    }

    /**
     * @param array<string, string>         $labels
     * @param array<string, AuditFieldKind> $kinds
     */
    private function transformer(array $labels = [], array $kinds = []): AuditLogTransformer
    {
        return new AuditLogTransformer($this->policy($labels, $kinds), $this->actorResolver(), $this->presenter());
    }

    private function entryWith(FieldChange $change, string $actorId = 'ulid-unknown'): AuditEntry
    {
        return new AuditEntry(
            'id',
            'App\\X',
            'c1',
            AuditAction::Updated,
            new ChangeSet($change),
            $actorId,
            new \DateTimeImmutable(),
        );
    }

    public function test_label_from_config_and_system_actor(): void
    {
        $t = $this->transformer(['title' => 'Название']);
        $v = $t->view($this->entryWith(FieldChange::set('title', 'X', 'Y'), 'system'));

        self::assertSame('Система', $v->actorLabel);
        self::assertCount(1, $v->changes);
        self::assertSame(ChangeOp::Set, $v->changes[0]->op);
        self::assertSame('Название', $v->changes[0]->label);
        self::assertSame('X', $v->changes[0]->oldText);
        self::assertSame('Y', $v->changes[0]->newText);
    }

    public function test_nested_head_label_keeps_tail_for_scalar_kind(): void
    {
        $t = $this->transformer(['minRecoatingInterval' => 'Дерево перекрытия']);
        $v = $t->view($this->entryWith(FieldChange::set('minRecoatingInterval.default', 1, 2)));

        self::assertSame('Дерево перекрытия · default', $v->changes[0]->label);
        self::assertSame('1', $v->changes[0]->oldText);
        self::assertSame('2', $v->changes[0]->newText);
    }

    public function test_known_actor_resolves_to_email(): void
    {
        $t = $this->transformer();
        $v = $t->view($this->entryWith(FieldChange::set('title', 'X', 'Y'), 'ulid-known'));

        self::assertSame('known@example.com', $v->actorLabel);
    }

    public function test_unknown_actor_falls_back_to_raw_id(): void
    {
        $t = $this->transformer();
        $v = $t->view($this->entryWith(FieldChange::set('title', 'X', 'Y'), 'ulid-unknown'));

        self::assertSame('ulid-unknown', $v->actorLabel);
    }

    public function test_add_change_has_empty_old_text(): void
    {
        $t = $this->transformer(['title' => 'Название']);
        $v = $t->view($this->entryWith(FieldChange::add('title', 'Y')));

        self::assertSame(ChangeOp::Add, $v->changes[0]->op);
        self::assertSame('', $v->changes[0]->oldText);
        self::assertSame('Y', $v->changes[0]->newText);
    }

    public function test_remove_change_has_empty_new_text(): void
    {
        $t = $this->transformer(['title' => 'Название']);
        $v = $t->view($this->entryWith(FieldChange::remove('title', 'X')));

        self::assertSame(ChangeOp::Remove, $v->changes[0]->op);
        self::assertSame('X', $v->changes[0]->oldText);
        self::assertSame('', $v->changes[0]->newText);
    }

    /** Сквозняк: правка точки серии высыхания даёт РОВНО одну человекочитаемую строку. */
    public function test_duration_series_point_edit_produces_single_readable_row(): void
    {
        $t = $this->transformer(
            ['dryToTouch' => 'Высыхание на отлип'],
            ['dryToTouch' => AuditFieldKind::DurationSeries],
        );
        $v = $t->view($this->entryWith(FieldChange::set('dryToTouch.5.time_in_minutes', 960, 900)));

        self::assertCount(1, $v->changes);
        self::assertSame('Высыхание на отлип, 5 °C', $v->changes[0]->label);
        self::assertSame('16 ч', $v->changes[0]->oldText);
        self::assertSame('15 ч', $v->changes[0]->newText);
    }

    /** is_calculated — служебный флаг серии, презентер сигналит null → строка скрыта, а не показана как шум. */
    public function test_is_calculated_change_is_hidden_from_view(): void
    {
        $t = $this->transformer(
            ['dryToTouch' => 'Высыхание на отлип'],
            ['dryToTouch' => AuditFieldKind::DurationSeries],
        );
        $v = $t->view($this->entryWith(FieldChange::set('dryToTouch.5.is_calculated', false, true)));

        self::assertSame([], $v->changes);
    }
}
