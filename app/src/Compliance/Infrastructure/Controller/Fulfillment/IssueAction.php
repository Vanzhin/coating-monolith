<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Command\RecordIssuance\RecordIssuanceCommand;
use App\Compliance\Application\UseCase\Query\GetIssuePage\GetIssuePageQuery;
use App\Compliance\Application\UseCase\Query\GetIssuePage\IssuePageDTO;
use App\Compliance\Domain\ValueObject\Unit;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Карточка требования у человека. Каждая позиция нормы — ЕДИНАЯ карточка: статус-бейдж/норма/на руках/посл.
 * выдача/след. срок — EAGER (из read-model {@see \App\Compliance\Application\DTO\ProfileCompliance\ObligationRowDTO}),
 * форма «Записать выдачу» — EAGER, журнал истории позиции (выдачи+списания) — LAZY, по требованию
 * ({@see ObligationHistoryAction}). Форма ВЫДАЧИ доступна
 * ВСЕГДА (не гейтится статусом нормы): рисует ВСЕ позиции текущей нормы с предзаполнением дефицитом (норма −
 * на руках, не ниже 0), количество переопределяемо — замену и досрочную выдачу можно записать даже при полностью
 * удовлетворённой (Green) норме. «Записать выдачу» пишет факты прямо в журнал ({@see RecordIssuanceCommand}).
 * СПИСАНИЕ — параллельный вход на той же странице (кнопка + акты списания аккордеоном): «Списать» ведёт в акт
 * списания ({@see GoToWriteOffAction}), доступно при held > 0.
 */
#[Route(path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/issue', name: 'app_cabinet_compliance_issue', methods: ['GET', 'POST'])]
final class IssueAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $profileId, string $requirementId): Response
    {
        if ($request->isMethod('POST')) {
            /** @var array<string, mixed> $inputData */
            $inputData = $request->getPayload()->all();
            // В запись идут только ОТМЕЧЕННЫЕ чекбоксом позиции (снятый чекбокс браузер в POST не присылает).
            // Персональные позиции (вне нормы) добавляются явной кнопкой — не гейтим.
            /** @var list<array<string, mixed>> $selectedItems */
            $selectedItems = array_values(array_filter(
                (array) ($inputData['items'] ?? []),
                static fn (mixed $row): bool => is_array($row) && !empty($row['selected']),
            ));
            try {
                $this->commandBus->execute(new RecordIssuanceCommand(
                    $profileId,
                    $requirementId,
                    (string) ($inputData['documentDate'] ?? ''),
                    $selectedItems,
                    array_values((array) ($inputData['personalItems'] ?? [])),
                    (string) ($inputData['cardNumber'] ?? ''),
                ));
                $this->addFlash('success', 'Выдача записана в журнал.');

                return $this->redirectToRoute('app_cabinet_compliance_dashboard', ['profile' => $profileId]);
            } catch (OptimisticLockException) {
                // Параллельная правка (двойной сабмит / другое окно): версия агрегата разошлась — данные целы,
                // просим обновить и повторить вместо 500.
                return $this->renderForm($profileId, $requirementId, 'Данные только что изменились в другом окне. Обновите страницу и повторите.', $inputData);
            } catch (AppException $e) {
                return $this->renderForm($profileId, $requirementId, $e->getMessage(), $inputData);
            }
        }

        return $this->renderForm($profileId, $requirementId, null, []);
    }

    /**
     * @param array<string, mixed> $inputData
     */
    private function renderForm(string $profileId, string $requirementId, ?string $error, array $inputData): Response
    {
        /** @var ?IssuePageDTO $page */
        $page = $this->queryBus->execute(new GetIssuePageQuery($profileId, $requirementId));
        if (null === $page) {
            throw $this->createNotFoundException('Профиль не найден.');
        }

        $this->mergeSubmittedState($page, $inputData);
        $documentDate = (string) ($inputData['documentDate'] ?? '') ?: date('Y-m-d');

        return $this->render('admin/compliance/person/issue.html.twig', [
            'profileId' => $profileId,
            'requirementId' => $requirementId,
            'requirementName' => $page->requirementName,
            'profile' => $page->profile,
            // № карточки СИЗ — свойство журнала (одна карточка на человека): уже закреплён → форма покажет
            // readonly; не закреплён → редактируемое поле, предзаполненное табельным (profile.personnelNumber).
            'cardNumber' => $page->cardNumber,
            'rows' => $page->rows,
            'requirementType' => $page->requirementType,
            'requirementRequiresQuantity' => $page->requirementRequiresQuantity,
            'instructionGroups' => $page->instructionGroups,
            'inputData' => $inputData,
            'documentDate' => $documentDate,
            'error' => $error,
            'units' => array_map(static fn (Unit $u): array => ['value' => $u->value, 'title' => $u->title()], Unit::cases()),
            // Параллельный блок списания на той же странице.
            'canWriteOff' => $page->canWriteOff,
            'writeOffActs' => $page->writeOffActs,
        ]);
    }

    /**
     * Накладывает состояние ТЕКУЩЕГО запроса на read-модель страницы: не сохранённая корзина (черновика нет —
     * прямая запись в журнал), поэтому восстанавливать нечего, кроме повторного рендера после ошибки сабмита
     * (поля инструктажа построчно из присланного POST) и отметки чекбокса группового действия. На первом
     * рендере ($inputData пуст) отметка по умолчанию — по наличию данных к выдаче: дефицит (кол-во > 0) у
     * материальных или заполненный инструктаж; «в норме» (дефицит 0) — снята.
     *
     * @param array<string, mixed> $inputData
     */
    private function mergeSubmittedState(IssuePageDTO $page, array $inputData): void
    {
        $submittedItems = (array) ($inputData['items'] ?? []);
        $isResubmit = [] !== $submittedItems;
        foreach ($page->rows as $i => $row) {
            $submitted = (array) ($submittedItems[$i] ?? []);
            $row->savedInstruction = (array) ($submitted['instruction'] ?? []);
            if ($isResubmit) {
                $row->selected = isset($submitted['selected']);
            } else {
                $amount = str_replace(',', '.', (string) ($row->deficitValue ?? ''));
                $hasAmount = is_numeric($amount) && (float) $amount > 0.0;
                $hasInstruction = [] !== array_filter($row->savedInstruction, static fn ($v): bool => '' !== (string) $v);
                $row->selected = $hasAmount || $hasInstruction;
            }
        }
    }
}
