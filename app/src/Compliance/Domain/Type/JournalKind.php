<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Type;

use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Вид журнала не материального требования (инструктажа). Config-first: каждый вид САМ объявляет схему полей
 * ({@see fields()}) — набор отличается (у пожарного теор./практ. части, у ОТ причина + локальные акты). Форма
 * оформления и проектор документа идут по схеме обобщённо. Новый журнал = новый case + его список полей.
 */
enum JournalKind: string
{
    case FireSafety = 'fire_safety';
    case WorkplaceOt = 'workplace_ot';

    public function label(): string
    {
        return match ($this) {
            self::FireSafety => 'Журнал противопожарных инструктажей',
            self::WorkplaceOt => 'Журнал инструктажа на рабочем месте (ОТ)',
        };
    }

    /** Проверить, что обязательные поля схемы заполнены (при подписи акта). Пусто/нет → AppException. */
    public function assertDetailsComplete(?InstructionDetails $details): void
    {
        $missing = [];
        foreach ($this->fields() as $field) {
            if ($field->required && !($details?->has($field->key) ?? false)) {
                $missing[] = $field->label;
            }
        }
        if ([] !== $missing) {
            throw new AppException('Заполните обязательные поля инструктажа: '.implode(', ', $missing).'.');
        }
    }

    /** @return list<FieldSpec> */
    public function fields(): array
    {
        $kinds = ['Первичный', 'Повторный', 'Внеплановый', 'Целевой'];

        return match ($this) {
            self::FireSafety => [
                new FieldSpec('instruction_kind', 'Вид инструктажа', FieldKind::Select, true, $kinds),
                new FieldSpec('instructor_fio', 'Инструктирующий (ФИО)', FieldKind::Text, true),
                new FieldSpec('instructor_doc', 'Документ инструктирующего (№ протокола/удостоверения, дата)', FieldKind::Text, true),
                new FieldSpec('practice_date', 'Дата практической части', FieldKind::Date),
            ],
            self::WorkplaceOt => [
                new FieldSpec('instruction_kind', 'Вид инструктажа', FieldKind::Select, true, $kinds),
                new FieldSpec('reason', 'Причина (для внепланового/целевого)', FieldKind::Text),
                new FieldSpec('instructor_fio', 'Инструктирующий (ФИО)', FieldKind::Text, true),
                new FieldSpec('instructor_doc', 'Документ инструктирующего (удостоверение/рег. №, дата)', FieldKind::Text, true),
            ],
        };
    }
}
