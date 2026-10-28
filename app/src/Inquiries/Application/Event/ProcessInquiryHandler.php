<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Event;

use App\Inquiries\Application\Exception\InquiryProcessingException;
use App\Inquiries\Application\Service\InquiryProcessor;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Event\InquirySubmitted;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Event\EventHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Psr\Log\LoggerInterface;

/**
 * Диспетчер обработки (в воркере, async). Тонкий: грузит запрос, выбирает процессор по типу,
 * прогоняет, сохраняет. Ошибка любого шага → inquiry.fail(причина) и сохранить (не пробрасываем —
 * иначе messenger ретраит и снова дёрнет модель). Идемпотентность: обрабатываем только Received,
 * повторную доставку события пропускаем.
 */
final readonly class ProcessInquiryHandler implements EventHandlerInterface
{
    /** @param iterable<InquiryProcessor> $processors */
    public function __construct(
        private iterable $processors,
        private InquiryRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(InquirySubmitted $event): void
    {
        $inquiry = $this->repository->findOneById($event->inquiryId);
        if (null === $inquiry) {
            $this->logger->warning('Запрос для обработки не найден', ['id' => $event->inquiryId]);

            return;
        }
        if (InquiryStatus::Received !== $inquiry->getStatus()) {
            return; // уже обработан — повторная доставка события
        }

        $processor = $this->processorFor($inquiry->getType());
        $now = new \DateTimeImmutable();
        if (null === $processor) {
            $inquiry->fail(sprintf('Нет обработчика для типа «%s».', $inquiry->getType()->label()), $now);
            $this->repository->add($inquiry);

            return;
        }

        try {
            $processor->process($inquiry);
        } catch (InquiryProcessingException $e) {
            // Ожидаемый категоризированный отказ шага (пусто/не распознано/модель/поиск) — не системный сбой.
            $this->logger->warning('Запрос не обработан: '.$e->getMessage(), ['id' => $inquiry->getId(), 'exception' => $e]);
            $inquiry->fail($e->getMessage(), new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->error('Обработка запроса провалилась', ['id' => $inquiry->getId(), 'exception' => $e]);
            $inquiry->fail($e instanceof AppException ? $e->getMessage() : 'Внутренняя ошибка обработки.', new \DateTimeImmutable());
        }

        $this->repository->add($inquiry);
    }

    private function processorFor(InquiryType $type): ?InquiryProcessor
    {
        foreach ($this->processors as $processor) {
            if ($processor->supportedType() === $type) {
                return $processor;
            }
        }

        return null;
    }
}
