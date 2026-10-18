<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Application\Service;

use App\Compliance\Application\Service\WriteOffActProjector;
use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Personnel\Application\DTO\Profile\ProfileDTO;
use App\Shared\Domain\Templating\RepeatValue;
use App\Shared\Domain\Templating\TextValue;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Проектор docx-акта списания на кросс-персон агрегате: один акт покрывает позиции нескольких людей одной
 * организации. Render-данные должны нести ФИО всех людей, строки обоих (сгруппированные по человеку, с ФИО на
 * каждой строке), единую организацию.
 */
final class WriteOffActProjectorTest extends TestCase
{
    public function test_cross_person_act_carries_both_people_grouped_lines_and_single_org(): void
    {
        $act = new WriteOffAct(Uuid::v7(), 'req-1', 'org-1', new \DateTimeImmutable('2026-10-20'));
        $act->addLinesForProfile('p1', [
            ['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0],
            ['recordId' => 'r2', 'labelSnapshot' => 'Костюм х/б', 'quantity' => 1.0],
        ]);
        $act->addLinesForProfile('p2', [
            ['recordId' => 'r3', 'labelSnapshot' => 'Каска защитная', 'quantity' => 1.0],
        ]);

        $profiles = [
            'p1' => $this->profile('Иванов', 'Иван', 'Иванович', 'ООО Пример'),
            'p2' => $this->profile('Петров', 'Пётр', 'Петрович', 'ООО Пример'),
        ];

        $render = (new WriteOffActProjector())->project($act, $profiles, []);

        $org = $render->get('org_title');
        self::assertInstanceOf(TextValue::class, $org);
        self::assertSame('ООО Пример', $org->value, 'организация одна на акт');

        $fio = $render->get('employee_fio');
        self::assertInstanceOf(TextValue::class, $fio);
        self::assertStringContainsString('Иванов Иван Иванович', $fio->value);
        self::assertStringContainsString('Петров Пётр Петрович', $fio->value);

        $items = $render->get('items');
        self::assertInstanceOf(RepeatValue::class, $items);
        self::assertCount(3, $items->rows, 'строки обоих людей');

        // Сгруппировано по человеку: сначала обе строки p1, затем строка p2; ФИО на каждой строке.
        self::assertSame('Перчатки', $items->rows[0]['name']);
        self::assertSame('Иванов Иван Иванович', $items->rows[0]['employee_fio']);
        self::assertSame('Костюм х/б', $items->rows[1]['name']);
        self::assertSame('Иванов Иван Иванович', $items->rows[1]['employee_fio']);
        self::assertSame('Каска защитная', $items->rows[2]['name']);
        self::assertSame('Петров Пётр Петрович', $items->rows[2]['employee_fio']);
    }

    private function profile(string $last, string $first, string $middle, string $orgTitle): ProfileDTO
    {
        $dto = new ProfileDTO();
        $dto->lastName = $last;
        $dto->firstName = $first;
        $dto->middleName = $middle;
        $dto->organizationTitle = $orgTitle;

        return $dto;
    }
}
