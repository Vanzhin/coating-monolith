<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block;

/**
 * Стабильный ключ блока отчёта. Блоки — переиспользуемые единицы содержимого; тип отчёта
 * перечисляет свои блоки в порядке (ReportType::blockKeys()). Набор из разбора реальных актов.
 */
enum BlockKey: string
{
    case ControlArea = 'control_area';       // Контрольный участок
    case SurfacePrep = 'surface_prep';       // Подготовка поверхности
    case System = 'system';                  // Система АКЗ (план)
    case Instruments = 'instruments';        // Приборы контроля
    case Application = 'application';         // Нанесение по слоям
    case Process = 'process';                // Процесс/дефекты
    case Recommendations = 'recommendations'; // Рекомендации
    case Conclusion = 'conclusion';          // Выводы и заключение
    case Notes = 'notes';                    // Примечания
    case Photos = 'photos';                  // Фото/приложения
    case Commission = 'commission';          // Комиссия/подписанты
}
