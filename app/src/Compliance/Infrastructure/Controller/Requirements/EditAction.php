<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Controller\Requirements;

use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Query\GetRequirement\GetRequirementQuery;
use App\Compliance\Application\UseCase\Query\GetRequirement\GetRequirementQueryResult;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\Type\PeriodUnit;
use App\Compliance\Domain\ValueObject\Unit;
use App\Personnel\Application\UseCase\Query\GetPositionsByIds\GetPositionsByIdsQuery;
use App\Personnel\Application\UseCase\Query\GetPositionsByIds\GetPositionsByIdsQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Редактор требования: создание (create) и правка ({id}/edit) на одном экране. GET — форма (пустая/из
 * требования), POST — SaveRequirementCommand. Тип выбирается на создании и на правке залочен. Доменные
 * ошибки (пустое имя/основание, дубли, чужой тип) — inline, введённое сохраняется. Права — в хендлере.
 */
final class EditAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    #[Route(path: '/cabinet/compliance/requirements/create', name: 'app_cabinet_compliance_requirements_create', methods: ['GET', 'POST'])]
    #[Route(path: '/cabinet/compliance/requirements/{id}/edit', name: 'app_cabinet_compliance_requirements_edit', methods: ['GET', 'POST'], requirements: ['id' => '[0-9a-f\-]{36}'])]
    public function __invoke(Request $request, ?string $id = null): Response
    {
        if ($request->isMethod('POST')) {
            $payload = $request->getPayload();
            $name = (string) $payload->get('name', '');
            $type = (string) $payload->get('type', '');
            $positionIds = array_values(array_map('strval', (array) $payload->all('positionIds')));
            $items = array_values((array) $payload->all('items'));
            try {
                $this->commandBus->execute(new SaveRequirementCommand($id, $name, $type, $positionIds, $items));
                $this->addFlash('success', 'Требование сохранено.');

                return $this->redirectToRoute('app_cabinet_compliance_requirements_list');
            } catch (AppException $e) {
                return $this->renderForm($id, $e->getMessage(), [
                    'id' => $id,
                    'name' => $name,
                    'type' => $type,
                    'positions' => $this->resolvePositionChips($positionIds),
                    'items' => $items,
                ]);
            }
        }

        return $this->renderForm($id, null, null === $id
            ? ['name' => '', 'type' => '', 'positions' => [], 'items' => []]
            : $this->loadInput($id));
    }

    /**
     * @param array<string, mixed> $inputData
     */
    private function renderForm(?string $id, ?string $error, array $inputData): Response
    {
        return $this->render('admin/compliance/requirements/form.html.twig', [
            'id' => $id,
            'error' => $error,
            'inputData' => $inputData,
            'complianceTypes' => array_map(
                static fn (ComplianceType $t): array => ['value' => $t->value, 'title' => $t->title(), 'requiresQuantity' => $t->requiresQuantity()],
                ComplianceType::cases(),
            ),
            'cadenceKinds' => array_map(
                static fn (CadenceKind $k): array => ['value' => $k->value, 'title' => $k->title(), 'requiresNumber' => $k->requiresNumber()],
                CadenceKind::cases(),
            ),
            'units' => array_map(
                static fn (Unit $u): array => ['value' => $u->value, 'title' => $u->title()],
                Unit::cases(),
            ),
            'periodUnits' => array_map(
                static fn (PeriodUnit $u): array => ['value' => $u->value, 'title' => $u->title()],
                PeriodUnit::cases(),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadInput(string $id): array
    {
        /** @var GetRequirementQueryResult $result */
        $result = $this->queryBus->execute(new GetRequirementQuery($id));
        if (null === $result->requirement) {
            throw $this->createNotFoundException('Требование не найдено.');
        }
        $requirement = $result->requirement;

        return [
            'id' => $requirement->id,
            'name' => $requirement->name,
            'type' => $requirement->type,
            // Пустое название = id не резолвится в должность (мусор/удалённая должность) — не показываем.
            'positions' => array_values(array_filter(
                array_map(static fn ($p): array => ['id' => $p->id, 'title' => $p->title], $requirement->positions),
                static fn (array $p): bool => '' !== $p['title'],
            )),
            'items' => array_map(
                static fn ($i): array => [
                    'label' => $i->label,
                    'cadenceKind' => $i->cadenceKind,
                    'cadenceNumber' => $i->cadenceNumber,
                    'cadenceUnit' => $i->cadenceUnit,
                    'amount' => $i->amount,
                    'unit' => $i->unit,
                    'basis' => $i->basis,
                ],
                $requirement->items,
            ),
        ];
    }

    /**
     * Чипы должностей для ре-рендера после ошибки: названия дотягиваем из Personnel по id (шина).
     *
     * @param list<string> $ids
     *
     * @return list<array{id: string, title: string}>
     */
    private function resolvePositionChips(array $ids): array
    {
        $ids = array_values(array_filter($ids, static fn (string $id): bool => '' !== trim($id)));
        if ([] === $ids) {
            return [];
        }

        /** @var GetPositionsByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetPositionsByIdsQuery(new StringCollection(...$ids)));
        $titles = [];
        foreach ($result->positions as $position) {
            $titles[$position->id] = $position->title;
        }

        return array_map(static fn (string $id): array => ['id' => $id, 'title' => $titles[$id] ?? ''], $ids);
    }
}
