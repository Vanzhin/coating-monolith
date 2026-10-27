<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Жизненный цикл запроса: получен → распознан → подобрано → одобрен | ошибка.
 * «Одобрен» — терминально-замороженное состояние. Правка инженером = возврат Подобрано → Распознан
 * (новые параметры → переподбор). Переход валидирует Inquiry::transitionTo.
 */
enum InquiryStatus: string
{
    case Received = 'received';     // есть вход
    case Recognized = 'recognized'; // параметры получены (моделью/из кэша/вручную)
    case Matched = 'matched';       // подбор выполнен, снимок ответа есть
    case Approved = 'approved';     // инженер одобрил (заморозка)
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Получен',
            self::Recognized => 'Распознан',
            self::Matched => 'Подобрано',
            self::Approved => 'Одобрен',
            self::Failed => 'Ошибка',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received => [self::Recognized, self::Failed],
            self::Recognized => [self::Matched, self::Failed],
            self::Matched => [self::Approved, self::Recognized, self::Failed], // Recognized = инженер поправил параметры → переподбор
            self::Failed => [self::Recognized],
            self::Approved => [], // терминально, заморожен
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
