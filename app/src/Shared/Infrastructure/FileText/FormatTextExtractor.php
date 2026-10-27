<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\FileText;

/**
 * Парсер одного семейства форматов для диспетчера FormatFileTextExtractor. Возвращает
 * сырой текст; при невозможности прочитать — бросает \Throwable (диспетчер превратит в
 * ERROR). Статусы/лимиты/нормализацию делает диспетчер, не адаптер.
 */
interface FormatTextExtractor
{
    /** @return list<string> расширения в нижнем регистре, которые берёт адаптер */
    public function extensions(): array;

    /** Сырой извлечённый текст. Бросает при ошибке чтения. */
    public function extract(string $path): string;

    /** true, если пустой результат значит «скан без текстового слоя» (PDF), а не просто пустой документ. */
    public function emptyMeansScan(): bool;
}
