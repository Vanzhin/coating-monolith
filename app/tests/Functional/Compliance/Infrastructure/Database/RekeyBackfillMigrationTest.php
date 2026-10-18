<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Database;

use App\Shared\Infrastructure\Database\Migrations\Version20261020120900;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Прод-бэкфилл rekey label→itemId: Version20261020120900 проставляет item_id в проекцию и переключает
 * compliance_fulfillment_record.obligation_key с reqId|lower(label) на reqId|itemId.
 *
 * Сеет через реальные агрегаты ({@see EnrollsComplianceTrait} — обходит кастомные DBAL-типы Personnel
 * (full_name/reference/sizes) и Compliance (cadence/quantity/items), которые сырым SQL пришлось бы
 * собирать вручную), затем сырым UPDATE откатывает ровно два поля к до-бэкфилл состоянию, которое лежит
 * на проде: item_id = NULL на обязанности, факт на label-ключе.
 */
final class RekeyBackfillMigrationTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $this->conn = static::getContainer()->get(Connection::class);
    }

    public function test_norm_fact_and_obligation_rekeyed_to_item_id(): void
    {
        [$reqId, $itemId, , , $recordId] = $this->seedLabelKeyed('Перчатки');

        $this->runMigration();

        $factKey = $this->conn->fetchOne('SELECT obligation_key FROM compliance_fulfillment_record WHERE id = ?', [$recordId]);
        self::assertSame($reqId.'|'.$itemId, $factKey, 'факт переключён на itemId');

        // Ассерт item_id на compliance_tracked_obligation снят: Вариант B снял материализацию обязанностей с
        // агрегата (норма только в Requirement) — issueCard больше не создаёт строку этой таблицы, а в Task 7
        // она дропается. Актуальная половина rekey — переключение ключа ФАКТА (выше) — остаётся под контролем.
    }

    public function test_orphan_fact_label_not_in_norm_is_left_untouched(): void
    {
        [$reqId, , , , $recordId] = $this->seedLabelKeyed('Перчатки', factLabel: 'снятаяпозиция');

        $this->runMigration();

        $factKey = $this->conn->fetchOne('SELECT obligation_key FROM compliance_fulfillment_record WHERE id = ?', [$recordId]);
        self::assertSame($reqId.'|снятаяпозиция', $factKey, 'факт без совпадения с нормой остаётся на label-ключе (orphan)');
    }

    public function test_migration_is_idempotent(): void
    {
        [$reqId, $itemId, , , $recordId] = $this->seedLabelKeyed('Перчатки');

        $this->runMigration();
        $this->runMigration(); // второй прогон

        $factKey = $this->conn->fetchOne('SELECT obligation_key FROM compliance_fulfillment_record WHERE id = ?', [$recordId]);
        self::assertSame($reqId.'|'.$itemId, $factKey, 'повторный прогон не ломает уже переключенный ключ');
    }

    /**
     * Заводит норму+профиль+выдачу через реальные use case-ы (обязанность и факт получаются в актуальном
     * itemId-формате), затем откатывает obligation_key факта и item_id обязанности к label-состоянию,
     * которое нужно забэкфиллить. $factLabel ≠ $label имитирует orphan: факт записан под старым
     * наименованием позиции, которого уже нет в текущей норме.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string} [$reqId, $itemId, $label, $pcId, $recordId]
     */
    private function seedLabelKeyed(string $label, ?string $factLabel = null): array
    {
        $enrolled = $this->enrollCompliance($label);
        $this->issueCard($enrolled['profileId'], $enrolled['requirementId'], $enrolled['key']);

        $reqId = $enrolled['requirementId'];
        $itemId = substr($enrolled['key'], strpos($enrolled['key'], '|') + 1);

        $pcId = (string) $this->conn->fetchOne(
            'SELECT id FROM compliance_profile_compliance WHERE profile_id = ?',
            [$enrolled['profileId']],
        );
        $recordId = (string) $this->conn->fetchOne(
            'SELECT id FROM compliance_fulfillment_record WHERE profile_compliance_id = ? ORDER BY fulfilled_at DESC LIMIT 1',
            [$pcId],
        );

        // Откат к до-бэкфилл состоянию, как на проде: item_id ещё не проставлен, факт на label-ключе.
        $this->conn->executeStatement(
            'UPDATE compliance_tracked_obligation SET item_id = NULL WHERE profile_compliance_id = ? AND requirement_id = ?',
            [$pcId, $reqId],
        );
        $legacyKey = $reqId.'|'.mb_strtolower($factLabel ?? $label);
        $this->conn->executeStatement(
            'UPDATE compliance_fulfillment_record SET obligation_key = ? WHERE id = ?',
            [$legacyKey, $recordId],
        );

        return [$reqId, $itemId, $label, $pcId, $recordId];
    }

    private function runMigration(): void
    {
        $m = new Version20261020120900($this->conn, new NullLogger());
        $m->up(new Schema());
    }
}
