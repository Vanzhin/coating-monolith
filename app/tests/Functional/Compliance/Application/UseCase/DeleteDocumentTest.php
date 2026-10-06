<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\DeleteDocument\DeleteDocumentCommand;
use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\StartWriteOffAct\StartWriteOffActCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Core\Authentication\Token\PreAuthenticatedToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Админское удаление подписанного акта выдачи: каскад — документ + его записи + связанные подписанные списания
 * (опустевший акт списания уходит целиком). Пересчёт — async (в тесте не обрабатывается, проверяем удаление).
 */
final class DeleteDocumentTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_deleting_signed_act_cascades_records_and_writeoffs(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $bus = $c->get(CommandBusInterface::class);
        $repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $em = $c->get(EntityManagerInterface::class);

        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $signedDocId = $this->issueCard($p, $r, $k); // подписанный акт выдачи (held 10)

        // Частичное списание — подписанный акт списания по записи этого акта выдачи.
        $em->clear();
        $pc = $repo->findByProfile($p);
        self::assertNotNull($pc);
        $recordId = $pc->recordsForRequirement($r)[0]->getId();
        $bus->execute(new StartWriteOffActCommand($p, $r));
        $em->clear();
        $actId = $repo->findByProfile($p)?->openWriteOffDraftFor($r)?->getId();
        self::assertNotNull($actId);
        $commission = [['fio' => 'Сидоров С. С.', 'organization' => 'ООО Тест', 'position' => 'Инженер', 'date' => '2026-07-01']];
        $bus->execute(new SaveWriteOffActCommand($p, $actId, [['recordId' => $recordId, 'quantity' => 3.0, 'reason' => 'physical_wear']], 'А-5', '2026-07-01', $commission));
        $bus->execute(new SignWriteOffActCommand($p, $actId, $commission, 'А-5', '2026-07-01', $this->stageComplianceScan()));
        $em->clear();

        // Перед удалением: есть подписанный акт выдачи, записи и подписанный акт списания.
        $pc = $repo->findByProfile($p);
        self::assertNotEmpty($pc->signedDocumentsFor($r));
        self::assertNotEmpty($pc->recordsForRequirement($r));
        self::assertNotEmpty($pc->getWriteOffActs());

        // Удаляем документ.
        $bus->execute(new DeleteDocumentCommand($p, $signedDocId));
        $em->clear();

        $after = $repo->findByProfile($p);
        self::assertNotNull($after);
        self::assertEmpty($after->signedDocumentsFor($r), 'акт выдачи удалён');
        self::assertEmpty($after->recordsForRequirement($r), 'его записи удалены');
        self::assertEmpty($after->getWriteOffActs(), 'связанный акт списания удалён (его единственная порция ссылалась на удалённую запись)');
    }

    /**
     * Смешанный акт списания: порции сразу по ДВУМ актам выдачи. Удаляем один акт выдачи — его порция уходит,
     * но сам акт списания УЦЕЛЕВАЕТ (в нём осталась порция по другому, нетронутому акту выдачи). Это проверяет
     * гейт `removed > 0 && isEmpty()` в каскаде: без него удаление акта A снесло бы чужую порцию акта B (потеря данных).
     *
     * Второй акт выдачи возможен только при дефиците (инвариант: на руках с учётом выдачи не меньше нормы), а
     * дефицит после полной выдачи открывает лишь подписанное списание — отсюда реалистичный путь: выдали норму →
     * списали часть → до-выдали (второй акт) → общий акт списания по обоим → удалили первый акт выдачи.
     */
    public function test_deleting_act_preserves_writeoff_portions_from_another_act(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $bus = $c->get(CommandBusInterface::class);
        $repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $em = $c->get(EntityManagerInterface::class);
        $commission = [['fio' => 'Сидоров С. С.', 'organization' => 'ООО Тест', 'position' => 'Инженер', 'date' => '2026-07-01']];

        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        // 1. Акт выдачи A на полную норму 10.
        $docA = $this->issueCard($p, $r, $k, '10');
        $em->clear();
        $recA = $this->recordIdForDocument($repo, $p, $r, $docA);

        // 2. Списываем 7 с записи A (подписанный акт списания W1) — открываем дефицит под вторую выдачу.
        $this->signWriteOff($bus, $repo, $em, $p, $r, [['recordId' => $recA, 'quantity' => 7.0, 'reason' => 'physical_wear']], 'W-1', $commission);
        // Проекция (held) пересобирается по событию списания async — в тесте воркера нет, синхронизируем вручную,
        // иначе FormDraft не увидит дефицит.
        $c->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($p));
        $em->clear();

        // 3. Акт выдачи B на оставшийся дефицит 7 (на руках 3 + 7 = 10). issueCard отдаёт signed[0] — при двух
        // подписанных это может быть A, поэтому B опознаём как подписанный документ, отличный от A.
        $this->issueCard($p, $r, $k, '7');
        $em->clear();
        $pcAfterB = $repo->findByProfile($p);
        self::assertNotNull($pcAfterB);
        $docB = null;
        foreach ($pcAfterB->signedDocumentsFor($r) as $d) {
            if ($d->getId() !== $docA) {
                $docB = $d->getId();
                break;
            }
        }
        self::assertNotNull($docB, 'второй акт выдачи подписан');
        $recB = $this->recordIdForDocument($repo, $p, $r, $docB);

        // 4. ОДИН акт списания W2 с порциями сразу по обеим записям (A и B).
        $this->signWriteOff($bus, $repo, $em, $p, $r, [
            ['recordId' => $recA, 'quantity' => 1.0, 'reason' => 'physical_wear'],
            ['recordId' => $recB, 'quantity' => 1.0, 'reason' => 'physical_wear'],
        ], 'W-2', $commission);

        // 5. Удаляем ТОЛЬКО акт выдачи A.
        $bus->execute(new DeleteDocumentCommand($p, $docA));
        $em->clear();

        $after = $repo->findByProfile($p);
        self::assertNotNull($after);

        $docIds = array_map(static fn ($d) => $d->getId(), $after->signedDocumentsFor($r));
        self::assertNotContains($docA, $docIds, 'акт выдачи A удалён');
        self::assertContains($docB, $docIds, 'акт выдачи B уцелел');

        $recordIds = array_map(static fn ($rec) => $rec->getId(), $after->recordsForRequirement($r));
        self::assertNotContains($recA, $recordIds, 'запись акта A удалена');
        self::assertContains($recB, $recordIds, 'запись акта B уцелела');

        // W1 ссылался только на запись A → опустел → удалён. W2 держал обе → остался с одной (B).
        $acts = $after->getWriteOffActs();
        self::assertCount(1, $acts, 'смешанный акт списания уцелел (в нём осталась чужая порция), пустой — удалён');
        $itemRecordIds = array_map(static fn ($item) => $item->recordId(), $acts[0]->items());
        self::assertNotContains($recA, $itemRecordIds, 'порция удалённого акта убрана');
        self::assertContains($recB, $itemRecordIds, 'порция уцелевшего акта сохранена');
    }

    public function test_non_manager_cannot_delete_document(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $bus = $c->get(CommandBusInterface::class);

        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $docId = $this->issueCard($p, $r, $k);

        // Понижаем актора до обычного пользователя — снос подписанного акта доступен только админу/системе.
        $c->get(TokenStorageInterface::class)->setToken(
            new PreAuthenticatedToken(new InMemoryUser('user', null, ['ROLE_USER']), 'test', ['ROLE_USER'])
        );

        $this->expectException(ForbiddenException::class);
        $bus->execute(new DeleteDocumentCommand($p, $docId));
    }

    private function recordIdForDocument(ProfileComplianceRepositoryInterface $repo, string $profileId, string $requirementId, string $documentId): string
    {
        $pc = $repo->findByProfile($profileId);
        self::assertNotNull($pc);
        foreach ($pc->recordsForRequirement($requirementId) as $record) {
            if ($record->documentId() === $documentId) {
                return $record->getId();
            }
        }
        self::fail('Запись-факт для документа не найдена: '.$documentId);
    }

    /**
     * @param list<array{recordId: string, quantity: float, reason: string}>                 $portions
     * @param list<array{fio: string, organization: string, position: string, date: string}> $commission
     */
    private function signWriteOff(
        CommandBusInterface $bus,
        ProfileComplianceRepositoryInterface $repo,
        EntityManagerInterface $em,
        string $profileId,
        string $requirementId,
        array $portions,
        string $actNumber,
        array $commission,
    ): void {
        $bus->execute(new StartWriteOffActCommand($profileId, $requirementId));
        $em->clear();
        $actId = $repo->findByProfile($profileId)?->openWriteOffDraftFor($requirementId)?->getId();
        self::assertNotNull($actId);
        $bus->execute(new SaveWriteOffActCommand($profileId, $actId, $portions, $actNumber, '2026-07-01', $commission));
        $bus->execute(new SignWriteOffActCommand($profileId, $actId, $commission, $actNumber, '2026-07-01', $this->stageComplianceScan()));
        $em->clear();
    }

    private function stageComplianceScan(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'scan').'.pdf';
        file_put_contents($tmp, "%PDF-1.4\n%signed\n");

        return self::getContainer()->get(FileStorage::class)
            ->stage('u-'.uniqid('', true), new UploadedFile($tmp, 'scan.pdf', 'application/pdf', null, true))
            ->id();
    }
}
