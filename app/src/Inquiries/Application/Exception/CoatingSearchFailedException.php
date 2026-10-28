<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Параметры распознаны, но поиск систем (query-шина Coatings) упал. Отличаем от «не распознал»:
 * тут вход валиден, подвела сторона поиска. Причину кладём в текст и лог.
 */
final class CoatingSearchFailedException extends InquiryProcessingException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct(
            sprintf('Поиск систем не удался: %s', $previous->getMessage()),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['cause' => $previous::class],
            $previous,
        );
    }
}
