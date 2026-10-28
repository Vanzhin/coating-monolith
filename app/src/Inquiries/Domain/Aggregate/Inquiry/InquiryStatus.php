<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Жизненный цикл запроса: получен → распознан → одобрен | ошибка. Подобранные системы — НЕ состояние
 * запроса, а живой результат прогона распознанных параметров по каталогу (считается на показе), поэтому
 * отдельного «Подобрано» нет: как только параметры распознаны (Recognized), запрос готов к просмотру.
 * «Одобрен» — терминально-замороженное состояние. Правка параметров инженером — повторный Recognized.
 */
enum InquiryStatus: string
{
    case Received = 'received';     // есть вход, обрабатывается (сборка текста + распознавание)
    case Recognized = 'recognized'; // параметры распознаны — готово к просмотру (живой подбор)
    case Approved = 'approved';     // инженер одобрил (заморозка)
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Получен',
            self::Recognized => 'Распознан',
            self::Approved => 'Одобрен',
            self::Failed => 'Ошибка',
        };
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Received => [self::Recognized, self::Failed],
            // Recognized → Recognized: инженер правит параметры (перераспознавание с тем же статусом).
            self::Recognized => [self::Recognized, self::Approved, self::Failed],
            self::Failed => [self::Recognized, self::Received], // Recognized = ручная правка, Received = повторный прогон
            self::Approved => [], // терминально, заморожен
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** Запрос ещё в обработке (воркер собирает текст/распознаёт). Карточка поллит до завершения. */
    public function isProcessing(): bool
    {
        return self::Received === $this;
    }
}
