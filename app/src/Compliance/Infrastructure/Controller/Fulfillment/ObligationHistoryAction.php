<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Fulfillment;

use App\Compliance\Application\UseCase\Query\GetObligationHistory\GetObligationHistoryQuery;
use App\Compliance\Application\UseCase\Query\GetObligationHistory\GetObligationHistoryQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Партиал истории ОДНОЙ позиции (лента выдач+списаний) — лениво грузится на первое раскрытие карточки
 * на странице выдачи (см. Stimulus-контроллер obligation-history), не на рендере самой страницы. Просмотр
 * доступен любому авторизованному (как и вся карточка) — аутентификация в security.yaml, мутаций тут нет.
 *
 * `obligationKey` ({@see \App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation::keyOf}) =
 * `requirementId|lower(label)` — label приходит из формы и может содержать «/» («...до 1000В-1500В/50дж»),
 * пробелы, кириллицу. ПУТЬ-параметр Symfony матчит по умолчанию `[^/]++` — «/» внутри ключа ломает генерацию
 * URL (`path()` в issue.html.twig кидал 500 при рендере). Поэтому ключ — query-параметр `key`, он принимает
 * любые символы (URL-кодируется автоматически), в пути остаются только безопасные uuid.
 */
#[Route(
    path: '/cabinet/compliance/person/{profileId}/requirement/{requirementId}/obligation/history',
    name: 'app_cabinet_compliance_obligation_history',
    methods: ['GET'],
)]
final class ObligationHistoryAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request, string $profileId, string $requirementId): Response
    {
        $obligationKey = (string) $request->query->get('key', '');

        /** @var GetObligationHistoryQueryResult $result */
        $result = $this->queryBus->execute(new GetObligationHistoryQuery($profileId, $requirementId, $obligationKey));

        return $this->render('admin/compliance/person/_obligation_history.html.twig', [
            'events' => $result->events,
            'profileId' => $profileId, // для ссылки на страницу акта из события списания
        ]);
    }
}
