<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Exception;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * База семейства ошибок обработки запроса. Их бросает процессор на конкретном шаге конвейера,
 * диспетчер ловит и кладёт текст в Inquiry::failureReason — чтобы по записи было видно, ГДЕ
 * упало, а не гадать. Живут в Application (а не Domain): часть ошибок про модель/поиск —
 * инфраструктуру, которую домен-хранилище знать не должен.
 */
abstract class InquiryProcessingException extends AppException
{
}
