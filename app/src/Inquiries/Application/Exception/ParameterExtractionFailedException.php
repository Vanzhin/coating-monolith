<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * Шаг извлечения (модель/сеть) провалился: таймаут, 5xx, битый ответ. Причину (тип+сообщение)
 * кладём в текст и в лог — чтобы по Failed было видно, что упала именно модель, а не домен.
 */
final class ParameterExtractionFailedException extends InquiryProcessingException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct(
            sprintf('Извлечение параметров не удалось (модель/сеть): %s', $previous->getMessage()),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            ['cause' => $previous::class],
            $previous,
        );
    }
}
