<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Domain\Aggregate\Inquiry;

use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Aggregate\Inquiry\Verification;
use PHPUnit\Framework\TestCase;

/**
 * jsonb-VO обязаны пережить round-trip fromArray(jsonSerialize()) без потерь — на этом держатся
 * DBAL-типы, сериализующие их в колонки.
 */
final class ValueObjectRoundTripTest extends TestCase
{
    public function test_recognized_parameters_round_trip_preserves_values_origin_and_robot(): void
    {
        // робот дал environment=atmospheric, инженер поправил на immersion → ось Manual, оригинал робота сохранён
        $vo = RecognizedParameters::recognized(['environment' => 'atmospheric', 'substanceIds' => ['s1', 's2'], 'thermalTemperature' => 40])
            ->withManualEdits(['environment' => 'immersion', 'substanceIds' => ['s1', 's2'], 'thermalTemperature' => 40]);

        $restored = RecognizedParameters::fromArray($vo->jsonSerialize());

        self::assertSame($vo->toArray(), $restored->toArray()); // плоские эффективные значения — канон фильтра — целы
        self::assertSame(ParameterStrategy::Manual, $restored->originOf('environment')); // провенанс цел
        self::assertSame(ParameterStrategy::Model, $restored->originOf('thermalTemperature')); // дефолт — Model
        self::assertSame(['environment' => ['robot' => 'atmospheric', 'human' => 'immersion']], $restored->corrections()); // оригинал робота пережил round-trip
        self::assertSame($vo->jsonSerialize(), $restored->jsonSerialize()); // структурный round-trip без потерь
    }

    public function test_recognized_parameters_tolerates_legacy_flat(): void
    {
        // старые строки (до провенанса) хранят плоско ключ→значение → читаем, origin по умолчанию Model
        $vo = RecognizedParameters::fromArray(['environment' => 'immersion', 'substanceIds' => ['s1']]);

        self::assertSame(['environment' => 'immersion', 'substanceIds' => ['s1']], $vo->toArray());
        self::assertSame(ParameterStrategy::Model, $vo->originOf('environment'));
    }

    public function test_parameter_source(): void
    {
        $vo = new ParameterSource(ParameterStrategy::Cache, ['sourceInquiryId' => 'abc', 'similarity' => 0.94]);

        self::assertSame($vo->jsonSerialize(), ParameterSource::fromArray($vo->jsonSerialize())->jsonSerialize());
    }

    public function test_parameter_source_defaults_strategy_on_garbage(): void
    {
        self::assertSame(ParameterStrategy::Model, ParameterSource::fromArray(['strategy' => 'nonsense'])->strategy);
    }

    public function test_verification(): void
    {
        $vo = new Verification('engineer-ulid', new \DateTimeImmutable('2026-01-02 12:30:00'));

        self::assertSame($vo->jsonSerialize(), Verification::fromArray($vo->jsonSerialize())->jsonSerialize());
    }

    // --- defensive-ветки fromArray: битый/пустой jsonb деградирует, но НЕ роняет загрузку ---

    public function test_parameter_source_tolerates_missing_keys(): void
    {
        $vo = ParameterSource::fromArray([]);

        self::assertSame(ParameterStrategy::Model, $vo->strategy);
        self::assertSame([], $vo->details);
    }

    public function test_verification_tolerates_null_actor_and_missing_date(): void
    {
        // системное авто-одобрение (verifiedBy = null) + отсутствующая дата → «сейчас», не падает
        $vo = Verification::fromArray(['verifiedBy' => null]);

        self::assertNull($vo->verifiedBy);
        self::assertInstanceOf(\DateTimeImmutable::class, $vo->verifiedAt);
    }
}
