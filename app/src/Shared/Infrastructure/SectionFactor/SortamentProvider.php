<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\SectionFactor;

use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отдаёт сортамент профилей (дерево «стандарт → уровни каскада → размеры») как готовую JSON-строку.
 * Источник истины — бэкенд-датасет sortament.json рядом с этим классом (портирован из Ptm-Calculator).
 * Меняется редко; фронт читает через эндпоинт и кэширует у себя для офлайна.
 */
final class SortamentProvider
{
    private ?string $json = null;

    public function json(): string
    {
        if (null === $this->json) {
            $content = @file_get_contents(__DIR__.'/sortament.json');
            if (false === $content) {
                throw new AppException('Сортамент профилей недоступен.', Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            $this->json = $content;
        }

        return $this->json;
    }
}
