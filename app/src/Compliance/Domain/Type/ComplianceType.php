<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

use App\Compliance\Domain\ValueObject\Item\MaterialItem;
use App\Compliance\Domain\ValueObject\Item\PeriodicItem;
use App\Compliance\Domain\ValueObject\Item\RequirementItemInterface;

/**
 * Тип требования — единственный источник истины о том, чем требование является. Виды: материальное
 * (инвентарь, выдаётся в количестве, учитывается остаток — СИЗ) и периодическое (событие, которое всегда
 * в количестве, но остаток не ведётся — проходится/выдаётся раз в N: инструктаж, медосмотр, расходник).
 * Позиция типа не хранит: свой класс она заявляет через {@see RequirementItemInterface::type()}, а тип требования
 * решает, какой класс позиции собрать из хранилища ({@see self::makeItem()}) и какую позицию принять.
 * Новый вид = новый case + своя ветка в makeItem + свой класс позиции; ядро (Requirement) не трогаем.
 */
enum ComplianceType: string
{
    case Material = 'material';
    case Periodic = 'periodic';

    public function title(): string
    {
        return match ($this) {
            self::Material => 'Выдача',
            self::Periodic => 'Периодическая',
        };
    }

    /**
     * Оба вида требования выдаются/проходятся в количестве (различает их учёт остатка — только у материального).
     * Метод — именованная точка расширения: появится вид без количества, здесь будет match по кейсам
     * (сейчас `true` для обоих — phpstan иначе бьёт «always true» на exhaustive-сравнении с 2 кейсами).
     */
    public function requiresQuantity(): bool
    {
        return true;
    }

    /**
     * Журнал (инструктаж/процедура) — атрибут периодического требования, не выдачи СИЗ. Match вместо простого
     * bool, чтобы новый кейс был вынужден явно решить, несёт ли он журнал.
     */
    public function carriesJournal(): bool
    {
        return match ($this) {
            self::Material => false,
            self::Periodic => true,
        };
    }

    /**
     * Собрать позицию своего типа из массива (форма/jsonb). Единственная точка «тип → класс позиции»,
     * поэтому в хранилище дискриминатор не нужен: класс восстанавливается по типу требования.
     *
     * @param array<string, mixed> $data
     */
    public function makeItem(array $data): RequirementItemInterface
    {
        return match ($this) {
            self::Material => MaterialItem::fromArray($data),
            self::Periodic => PeriodicItem::fromArray($data),
        };
    }
}
