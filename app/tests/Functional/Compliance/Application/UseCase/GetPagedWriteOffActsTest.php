<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\DTO\WriteOffAct\WriteOffActListItemDTO;
use App\Compliance\Application\UseCase\Query\GetPagedWriteOffActs\GetPagedWriteOffActsQuery;
use App\Compliance\Application\UseCase\Query\GetPagedWriteOffActs\GetPagedWriteOffActsQueryResult;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActFilter;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Reports\Domain\Repository\CounterpartyRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Список актов списания ({@see GetPagedWriteOffActsQueryHandler}) — кросс-организационный для админа:
 * без фильтра видны акты ВСЕХ организаций, фильтр по organizationId сужает, имена требования/организации
 * резолвятся батчем (не денормализованы на акте).
 */
final class GetPagedWriteOffActsTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private QueryBusInterface $queryBus;
    private WriteOffActRepositoryInterface $writeOffActRepo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->queryBus = static::getContainer()->get(QueryBusInterface::class);
        $this->writeOffActRepo = static::getContainer()->get(WriteOffActRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_no_filter_returns_all_acts_across_organizations_and_resolves_names(): void
    {
        ['organizationId' => $orgA, 'requirementId' => $reqA, 'profileId' => $pA] = $this->enrollCompliance('Перчатки');
        ['organizationId' => $orgB, 'requirementId' => $reqB, 'profileId' => $pB] = $this->enrollCompliance('Каска');

        $act1 = $this->seedAct($reqA, $orgA, $pA);
        $this->seedAct($reqA, $orgA, $pA);
        $this->seedAct($reqB, $orgB, $pB);
        $this->reload();

        $result = $this->fetch();
        self::assertCount(3, $result->items, 'админ без фильтра видит акты всех организаций');

        $item1 = $this->findItem($result, $act1);
        $requirement = static::getContainer()->get(RequirementRepositoryInterface::class)->findOneById($reqA);
        self::assertNotNull($requirement);
        self::assertSame($requirement->getName(), $item1->requirementName, 'имя требования резолвится через RequirementRepositoryInterface::findOneById');

        $organization = static::getContainer()->get(CounterpartyRepositoryInterface::class)->findOneById($orgA);
        self::assertNotNull($organization);
        self::assertSame($organization->getTitle(), $item1->organizationTitle, 'название организации резолвится батчем GetProfilesByIds по первому profileId акта');
        self::assertSame(1, $item1->lineCount);
        self::assertSame(1, $item1->profileCount);
    }

    public function test_filter_by_organization_id_returns_only_its_acts(): void
    {
        ['organizationId' => $orgA, 'requirementId' => $reqA, 'profileId' => $pA] = $this->enrollCompliance('Перчатки');
        ['organizationId' => $orgB, 'requirementId' => $reqB, 'profileId' => $pB] = $this->enrollCompliance('Каска');

        $this->seedAct($reqA, $orgA, $pA);
        $this->seedAct($reqA, $orgA, $pA);
        $this->seedAct($reqB, $orgB, $pB);
        $this->reload();

        $result = $this->fetch(organizationId: $orgA);
        self::assertCount(2, $result->items, 'фильтр по организации сузил выборку до её собственных актов');
    }

    public function test_pagination_reports_more_pages_available(): void
    {
        ['organizationId' => $org, 'requirementId' => $req, 'profileId' => $p] = $this->enrollCompliance('Перчатки');
        $this->seedAct($req, $org, $p);
        $this->seedAct($req, $org, $p);
        $this->seedAct($req, $org, $p);
        $this->reload();

        $result = $this->fetch(pager: new Pager(1, 1));
        self::assertCount(1, $result->items, 'лимит страницы соблюдён');
        self::assertSame(3, $result->pager->total_items);
        self::assertGreaterThan($result->pager->page, (int) $result->pager->total_pages, 'страница 1 из 3 — есть ещё (hasMore)');
    }

    /** Прямое конструирование акта (минуя AddToWriteOffDraft) — для списка важен только состав, не бизнес-флоу наполнения. */
    private function seedAct(string $requirementId, string $organizationId, string $profileId): string
    {
        $act = new WriteOffAct(Uuid::v7(), $requirementId, $organizationId, new \DateTimeImmutable());
        $act->addLinesForProfile($profileId, [['recordId' => 'r-'.uniqid('', true), 'labelSnapshot' => 'Позиция', 'quantity' => 1.0]]);
        $this->writeOffActRepo->add($act);

        return $act->getId();
    }

    private function fetch(?string $organizationId = null, ?Pager $pager = null): GetPagedWriteOffActsQueryResult
    {
        $filter = new WriteOffActFilter(pager: $pager ?? Pager::fromPage(), organizationId: $organizationId);
        $result = $this->queryBus->execute(new GetPagedWriteOffActsQuery($filter));
        \assert($result instanceof GetPagedWriteOffActsQueryResult);

        return $result;
    }

    private function findItem(GetPagedWriteOffActsQueryResult $result, string $actId): WriteOffActListItemDTO
    {
        foreach ($result->items as $item) {
            if ($item->id === $actId) {
                return $item;
            }
        }

        self::fail('акт не найден в результате списка');
    }

    private function reload(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
