<?php

declare(strict_types=1);

namespace App\Reports\Infrastructure\Controller\Report;

use App\Reports\Application\DTO\Reports\ReportDTO;
use App\Reports\Application\Service\ReportFormPresenter;
use App\Reports\Application\UseCase\Command\SaveReportContent\SaveReportContentCommand;
use App\Reports\Application\UseCase\Command\SubmitForReview\SubmitForReviewCommand;
use App\Reports\Application\UseCase\Command\UpdateReportHeader\UpdateReportHeaderCommand;
use App\Reports\Application\UseCase\Query\GetReport\GetReportQuery;
use App\Reports\Application\UseCase\Query\GetReport\GetReportQueryResult;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Заполнение отчёта: форма из схемы блоков его типа. Сохранение — черновик (лёгкая валидация,
 * авто-«в работу»); «На проверку» — строгая валидация. Доступ (владелец/админ) — в хендлерах.
 */
#[Route(path: '/cabinet/report/{id}/fill', name: 'app_cabinet_report_fill', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
final class FillAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly ReportFormPresenter $presenter,
    ) {
    }

    public function __invoke(string $id, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $payload = $request->getPayload();
            $raw = $payload->all();
            $content = \is_array($raw['content'] ?? null) ? $raw['content'] : [];
            try {
                // Реквизиты и содержимое — на одной странице; шапку пишем первой (пере-засев плана
                // при смене системы), затем блоки (блок «Система» сохранение бережёт).
                $this->commandBus->execute(new UpdateReportHeaderCommand(
                    reportId: $id,
                    reportDate: $this->parseDate((string) $payload->get('reportDate')),
                    actNumber: $this->nullify((string) $payload->get('actNumber')),
                    projectId: $this->nullify((string) $payload->get('projectId')),
                    customerId: $this->nullify((string) $payload->get('customerId')),
                    contractorId: $this->nullify((string) $payload->get('contractorId')),
                    address: $this->nullify((string) $payload->get('address')),
                    workPeriod: $this->interval(
                        $this->parseDate((string) $payload->get('workFrom')),
                        $this->parseDate((string) $payload->get('workTo')),
                    ),
                ));
                $this->commandBus->execute(new SaveReportContentCommand($id, $content));
                if ('submit' === $payload->get('action')) {
                    $this->commandBus->execute(new SubmitForReviewCommand($id));
                }

                $this->addFlash('success', 'submit' === $payload->get('action')
                    ? 'Отчёт отправлен на проверку.'
                    : 'Изменения сохранены.');

                return $this->redirectToRoute('app_cabinet_report_fill', ['id' => $id]);
            } catch (AppException $e) {
                // ТОЛЬКО доменные ошибки (валидация/доступ) показываем inline, введённое сохраняем из
                // формы. Технические (ФС/сеть и пр.) НЕ ловим — их подхватит MutationErrorListener:
                // залогирует с ref-кодом и покажет generic-тост, не утекая внутренностями наружу.
                return $this->renderForm($id, $content, $e->getMessage(), $this->inputFromPayload($payload));
            }
        }

        $report = $this->loadReport($id);

        return $this->renderForm($id, $report->content);
    }

    /**
     * @param array<string, mixed>      $content
     * @param array<string, mixed>|null $input   значения реквизитов для формы; null → из отчёта
     */
    private function renderForm(string $id, array $content, ?string $error = null, ?array $input = null): Response
    {
        $report = $this->loadReport($id);
        // Блок «Система» — readOnly, в POST его нет. На ре-рендере после ошибки берём засеянный
        // снимок из отчёта, иначе секция показала бы «Система без слоёв».
        if (isset($report->content['system'])) {
            $content['system'] = $report->content['system'];
        }
        $type = null !== $report->typeKey ? ReportType::from($report->typeKey) : null;

        return $this->render('cabinet/report/fill.html.twig', [
            'report' => $report,
            'sections' => null !== $type ? $this->presenter->sections($type, $content) : [],
            'error' => $error,
            'input' => $input ?? $this->inputFromReport($report),
        ]);
    }

    private function loadReport(string $id): ReportDTO
    {
        $result = $this->queryBus->execute(new GetReportQuery($id));
        \assert($result instanceof GetReportQueryResult);
        if (null === $result->report) {
            throw $this->createNotFoundException('Отчёт не найден.');
        }

        return $result->report;
    }

    /** @return array<string, mixed> */
    private function inputFromPayload(InputBag $payload): array
    {
        return [
            'actNumber' => (string) $payload->get('actNumber'),
            'reportDate' => (string) $payload->get('reportDate'),
            'address' => (string) $payload->get('address'),
            'workFrom' => (string) $payload->get('workFrom'),
            'workTo' => (string) $payload->get('workTo'),
            'customer' => $this->refFromPayload($payload, 'customerId', 'customerTitle'),
            'contractor' => $this->refFromPayload($payload, 'contractorId', 'contractorTitle'),
            'project' => $this->refFromPayload($payload, 'projectId', 'projectTitle'),
        ];
    }

    /** @return array<string, mixed> */
    private function inputFromReport(ReportDTO $report): array
    {
        return [
            'actNumber' => (string) $report->actNumber,
            'reportDate' => (string) $report->reportDate,
            'address' => (string) $report->address,
            'workFrom' => (string) $report->workFrom,
            'workTo' => (string) $report->workTo,
            'customer' => null !== $report->customerId ? ['id' => $report->customerId, 'title' => $report->customerTitle] : null,
            'contractor' => null !== $report->contractorId ? ['id' => $report->contractorId, 'title' => $report->contractorTitle] : null,
            'project' => null !== $report->projectId ? ['id' => $report->projectId, 'title' => $report->projectTitle] : null,
        ];
    }

    /**
     * Чип ссылки из данных формы: id + title-компаньон (его кладёт typeahead).
     *
     * @return array{id: string, title: string}|null
     */
    private function refFromPayload(InputBag $payload, string $idKey, string $titleKey): ?array
    {
        $id = $this->nullify((string) $payload->get($idKey));
        if (null === $id) {
            return null;
        }

        return ['id' => $id, 'title' => $this->nullify((string) $payload->get($titleKey)) ?? ''];
    }

    private function nullify(string $value): ?string
    {
        return '' !== trim($value) ? trim($value) : null;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        return '' !== $value ? new \DateTimeImmutable($value) : null;
    }

    private function interval(?\DateTimeImmutable $from, ?\DateTimeImmutable $to): ?DateTimeInterval
    {
        return null === $from && null === $to ? null : new DateTimeInterval($from, $to);
    }
}
