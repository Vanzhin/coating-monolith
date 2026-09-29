<?php

declare(strict_types=1);

namespace App\Personnel\Application\Service;

use App\Personnel\Domain\Aggregate\Profile\FullName;
use App\Personnel\Domain\Aggregate\Profile\Profile;
use App\Personnel\Domain\Aggregate\Profile\Sizes;
use App\Personnel\Domain\Aggregate\Profile\Specification\ProfileSpecification;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Personnel\Domain\Repository\PositionRepositoryInterface;
use App\Personnel\Domain\ValueObject\Reference;
use App\Reports\Application\UseCase\Query\GetCounterpartiesByIds\GetCounterpartiesByIdsQuery;
use App\Reports\Application\UseCase\Query\GetCounterpartiesByIds\GetCounterpartiesByIdsQueryResult;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Собирает `Profile`: резолвит снапшоты должности/организации/отдела по id (`Reference{id,title}`)
 * и проверяет связку «отдел принадлежит выбранной организации». Проверка требует фетч `Department`
 * по id — агрегат `Profile` его сделать не может (нет доступа к репозиторию), поэтому связка живёт
 * здесь, а не в конструкторе агрегата (см. Profile — там явный комментарий про это).
 *
 * Организация — `Counterparty` чужого контекста (Reports). Резолвится ТОЛЬКО через опубликованный
 * query-бас (`GetCounterpartiesByIdsQuery`+DTO), без обращения к домену/репозиторию Reports напрямую —
 * тот же приём, что `Reports\Application\Service\LayerMaterialResolver` использует для Coatings.
 *
 * Живёт в Application (не в Domain/Factory, как для CoatingMaker/DocumentFactory): единственная
 * причина — зависимость от `QueryBusInterface`, который сам принадлежит Application-слою
 * (`Shared\Application\Query`), и Domain не должен зависеть от Application ни для одного контекста
 * проекта (grep по кодовой базе это подтверждает — QueryBusInterface используется только в
 * Application/Infrastructure). Сборка снапшотов и связка «отдел↔организация» — по-прежнему каркас
 * и предпосылка для конструктора агрегата, бизнес-инварианты содержимого профиля остаются в Profile/VO.
 */
final readonly class ProfileMaker
{
    public function __construct(
        private PositionRepositoryInterface $positionRepository,
        private DepartmentRepositoryInterface $departmentRepository,
        private QueryBusInterface $queryBus,
        private ProfileSpecification $specification,
    ) {
    }

    public function make(
        string $userUlid,
        FullName $fullName,
        string $positionId,
        string $organizationId,
        string $departmentId,
        Sizes $sizes,
        ?string $personnelNumber,
        ?\DateTimeImmutable $hiredAt,
    ): Profile {
        $position = $this->resolvePosition($positionId);
        $department = $this->resolveDepartment($departmentId, $organizationId);
        $organization = $this->resolveOrganization($organizationId);

        return new Profile(
            UuidService::generate(),
            $userUlid,
            $fullName,
            $position,
            $organization,
            $department,
            $sizes,
            $personnelNumber,
            $hiredAt,
            $this->specification,
            new \DateTimeImmutable(),
        );
    }

    public function resolvePosition(string $positionId): Reference
    {
        $position = $this->positionRepository->findOneById($positionId);
        if (null === $position) {
            throw new AppException('Должность не найдена.', Response::HTTP_NOT_FOUND);
        }

        return new Reference($position->getId(), $position->getTitle());
    }

    public function resolveOrganization(string $organizationId): Reference
    {
        $result = $this->queryBus->execute(new GetCounterpartiesByIdsQuery(new StringCollection($organizationId)));
        \assert($result instanceof GetCounterpartiesByIdsQueryResult);

        foreach ($result->counterparties as $counterparty) {
            if ($counterparty->id === $organizationId) {
                return new Reference($counterparty->id, $counterparty->title);
            }
        }

        throw new AppException('Организация не найдена.', Response::HTTP_NOT_FOUND);
    }

    /** Связка «отдел принадлежит выбранной организации» — требует фетч Department, поэтому здесь. */
    public function resolveDepartment(string $departmentId, string $organizationId): Reference
    {
        $department = $this->departmentRepository->findOneById($departmentId);
        if (null === $department) {
            throw new AppException('Отдел не найден.', Response::HTTP_NOT_FOUND);
        }
        if ($department->getCompanyId() !== $organizationId) {
            throw new AppException('Отдел не принадлежит выбранной организации.');
        }

        return new Reference($department->getId(), $department->getTitle());
    }
}
