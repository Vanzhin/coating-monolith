<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\InquiryProcessedData;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Текст уведомления «запрос на подбор обработан»: распознан — зовём смотреть подбор; провален — с поводом.
 * Короткий текст + ссылка на карточку (хост — из router.default_uri/DOMAIN, в коде домен не пишем).
 */
final readonly class InquiryProcessedRenderer implements MessageRendererInterface
{
    private const SHOW_ROUTE = 'app_cabinet_inquiry_show';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function type(): NotificationType
    {
        return NotificationType::InquiryProcessed;
    }

    public function render(NotifiableEvent $event): string
    {
        if (!$event instanceof InquiryProcessedData) {
            throw new AppException('Событие inquiry.processed не реализует InquiryProcessedData.');
        }

        $link = $this->urls->generate(self::SHOW_ROUTE, ['id' => $event->inquiryId()], UrlGeneratorInterface::ABSOLUTE_PATH);

        if ($event->isFailed()) {
            $reason = $event->failureReason();
            $head = null !== $reason && '' !== $reason
                ? sprintf('Запрос на подбор не удалось обработать: %s', $reason)
                : 'Запрос на подбор не удалось обработать.';

            return sprintf("%s\n%s", $head, $link);
        }

        return sprintf("Запрос на подбор распознан — можно смотреть подбор.\n%s", $link);
    }
}
