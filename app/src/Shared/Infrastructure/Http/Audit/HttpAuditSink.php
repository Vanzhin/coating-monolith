<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http\Audit;

/**
 * Куда складывается телеметрия исходящих HTTP-запросов. Инфраструктурный порт:
 * сегодня Postgres (PostgresHttpAuditSink), завтра ClickHouse — подмена одной
 * реализации, декоратор и места вызова не трогаются. Реализация ОБЯЗАНА быть
 * best-effort безопасной стороной вызова: исключение стока не должно ломать
 * реальный HTTP-вызов (гасится выше, в декораторе/хендлере).
 */
interface HttpAuditSink
{
    public function record(HttpAuditRecord $record): void;
}
