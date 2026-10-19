<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\RecordIssuance\RecordIssuanceCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommandResult;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\UuidService;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * Карточка требования: журнальная форма выдачи (черновика/подписи/скана больше нет). GET с дефицитом рисует
 * форму с одной кнопкой «Записать выдачу»; сабмит server-render формы пишет факт прямо в журнал (held растёт,
 * акта-документа не возникает) и редиректит на карточку человека.
 */
final class IssueActionTest extends WebTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();

        $user = new User(new Email('issue_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $container->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
        $this->authenticateAsSystem();
    }

    public function test_get_renders_journal_issue_form_without_draft_or_scan(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance(); // дефицит: на руках 0 < норма 10

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Выдать выбранные', $html, 'групповое действие выдачи в савбаре');
        self::assertStringContainsString('Перчатки', $html, 'позиция текущей нормы в форме');
        self::assertStringNotContainsString('Сохранить черновик', $html, 'черновика больше нет');
        self::assertStringNotContainsString('Прикрепить скан', $html, 'секция скана убрана');
    }

    public function test_submit_records_issuance_as_journal_fact_and_redirects(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        // Дефицитная позиция отмечена по умолчанию (чекбокс checked), кол-во предзаполнено дефицитом (10) —
        // сабмитим форму как есть, без JS (бэк пишет только отмеченные).
        $this->client->submit($crawler->filter('#issue-form')->form());

        self::assertResponseRedirects();
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(10.0, $pc->heldOf($k), 1e-9, 'выдача записана прямо в журнал, на руках = норма');
    }

    public function test_get_renders_issue_form_even_when_norm_is_fully_green(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // выдано 10 = норма → позиция Green (дефицита нет)

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Выдать выбранные', $html, 'действие выдачи доступно даже при полностью удовлетворённой норме');
        self::assertStringContainsString('Перчатки', $html, 'Green-позиция присутствует в форме');
        self::assertGreaterThan(
            0,
            $crawler->filter('input[name="items[0][amount]"]:not([disabled])')->count(),
            'поле количества редактируемо (замена сверх нормы возможна)',
        );
    }

    public function test_submit_on_green_norm_records_replacement_and_grows_held_beyond_norm(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // на руках = норма = 10

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#issue-form')->form();
        $selected = $form['items[0][selected]'];
        self::assertInstanceOf(ChoiceFormField::class, $selected);
        $selected->tick(); // Green-позиция по умолчанию снята — отмечаем для выдачи
        $form['items[0][amount]'] = '5'; // переопределяем нулевой дефицит — досрочная замена
        $this->client->submit($form);
        self::assertResponseRedirects();

        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(15.0, $pc->heldOf($k), 1e-9, 'замена записана в журнал: на руках выросло сверх нормы (10 + 5)');
    }

    public function test_submit_skips_positions_left_unchecked(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('#issue-form')->form();
        $selected = $form['items[0][selected]'];
        self::assertInstanceOf(ChoiceFormField::class, $selected);
        $selected->untick(); // снимаем отметку — позиция не должна попасть в запись
        $this->client->submit($form);
        self::assertResponseRedirects();

        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        self::assertEqualsWithDelta(0.0, $pc->heldOf($k), 1e-9, 'снятая позиция в журнал не записана');
    }

    public function test_item_card_shows_status_badge_and_lazy_history_toggle(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('не выдано', $html, 'единая карточка: статус-бейдж того же бакета, что у дашборда (Missing — дефицит)');
        self::assertStringContainsString('История', $html, 'ленивый тоггл истории позиции присутствует в карточке');
        self::assertStringContainsString(
            sprintf('/cabinet/compliance/person/%s/requirement/%s/obligation/', $p, $r),
            $html,
            'url партиала истории зашит в data-атрибут карточки',
        );
    }

    /**
     * Регрессия (прод 500): наименование позиции с «/» (напр. «Каска защитная до 1000В-1500В/50дж») делает
     * obligationKey = requirementId|lower(label) с «/» внутри. Ключ истории — query-параметр, не путь-сегмент
     * (см. ObligationHistoryAction) — иначе `path()` в карточке кидает 500 на генерации URL партиала.
     */
    public function test_item_card_renders_without_500_when_label_contains_slash(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance('Каска защитная до 1000В-1500В/50дж');

        $this->client->request('GET', $this->issueUrl($p, $r));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('key=', (string) $this->client->getResponse()->getContent(), 'ключ ушёл в query, не в путь');
    }

    /**
     * Пин Task 4 (структурный cadence в проекции): позиция нормы отдаёт РЕАЛЬНЫЙ kind/число/единицу в
     * data-атрибуты поля даты (не пустую строку) — ими кормится клиентский AJAX-предпросмотр «след. выдача»
     * ({@see \App\Compliance\Infrastructure\Controller\Fulfillment\NextDueAction}). С теми же значениями,
     * что форма реально отправит, бэк должен посчитать дату (не null) — до структурного cadence в проекции
     * (только label-снимок) kind/число/единица были бы пустыми, и превью всегда показывал бы «—».
     */
    public function test_norm_position_cadence_struct_feeds_next_due_preview(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance(); // Перчатки: periodic, 1 год

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $dateInput = $crawler->filter('input[data-issue-date]')->first();
        self::assertSame('periodic', $dateInput->attr('data-cadence-kind'), 'норменная позиция отдаёт структурный cadence из проекции, не пустую строку');
        self::assertSame('1', $dateInput->attr('data-cadence-number'));
        self::assertSame('year', $dateInput->attr('data-cadence-unit'));

        // Те же значения — на бэк-калькулятор превью: должен вернуть дату, а не null.
        $this->client->request('GET', '/cabinet/compliance/next-due?date=2026-06-01&kind=periodic&number=1&unit=year');
        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('2027-06-01', $body['data']['nextDue'], 'предпросмотр считает дату по cadence из проекции, не «—»');
    }

    public function test_writeoff_entry_available_when_something_is_issued(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // есть выданное → списание доступно параллельно

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('write-off/open', $html, 'вход «Списать» доступен на той же странице, что и выдача');
        self::assertStringContainsString('Списать', $html);
    }

    /**
     * Task 6: /issue теперь читает read-модель через GetIssuePageQuery (view-builder в Application), не
     * собирает её прямо в контроллере. Три позиции одной нормы с разными датами выдачи → разные бакеты
     * (просрочено/скоро/в норме); сортировка по срочности — просроченная выше позиции со скорым сроком, та выше
     * позиции в норме (тот же порядок, что при сборке в контроллере раньше).
     */
    public function test_issue_page_renders_positions_sorted_by_urgency(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $keyOverdue] = $this->enrollCompliance(
            'Просрочено-позиция',
            extraItems: [
                ['label' => 'Скоро-позиция', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5'],
                ['label' => 'ОК-позиция', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5'],
            ],
        );
        $keySoon = $this->obligationKeyFor($r, 'Скоро-позиция');
        $keyOk = $this->obligationKeyFor($r, 'ОК-позиция');

        $now = new \DateTimeImmutable();
        static::getContainer()->get(CommandBusInterface::class)->execute(new RecordIssuanceCommand(
            $p,
            $r,
            $now->format('Y-m-d'),
            [
                // Периодичность 1 год: дата выдачи 400 дн. назад → след. срок уже прошёл (просрочено).
                ['obligationKey' => $keyOverdue, 'date' => $now->modify('-400 days')->format('Y-m-d'), 'amount' => '10', 'unit' => 'pair'],
                // 335 дн. назад → след. срок через ~30 дн. — внутри горизонта «скоро» (60 дн.).
                ['obligationKey' => $keySoon, 'date' => $now->modify('-335 days')->format('Y-m-d'), 'amount' => '10', 'unit' => 'pair'],
                // 10 дн. назад → след. срок через ~355 дн. — далеко за горизонтом, в норме.
                ['obligationKey' => $keyOk, 'date' => $now->modify('-10 days')->format('Y-m-d'), 'amount' => '10', 'unit' => 'pair'],
            ],
            cardNumber: 'CARD-TEST',
        ));
        // Write-through проектора прошёл в той же транзакции команды; очищаем identity map перед чтением
        // проекции через DQL (upsertMany пишет мимо ORM — закэшированный инстанс иначе отдаст старые даты).
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $posOverdue = strpos($html, 'Просрочено-позиция');
        $posSoon = strpos($html, 'Скоро-позиция');
        $posOk = strpos($html, 'ОК-позиция');
        self::assertNotFalse($posOverdue, 'просроченная позиция присутствует в форме');
        self::assertNotFalse($posSoon, 'позиция со скорым сроком присутствует в форме');
        self::assertNotFalse($posOk, 'позиция в норме присутствует в форме');
        self::assertLessThan($posSoon, $posOverdue, 'просроченная позиция рендерится выше позиции со скорым сроком');
        self::assertLessThan($posOk, $posSoon, 'позиция со скорым сроком рендерится выше позиции в норме');
    }

    /**
     * Task 4: периодическая-расходник (крем, норма 1200 мл) — «на руках» на `/issue` = ПОСЛЕДНЯЯ выдача
     * (T2 held), не сумма. Доввыдача 200 → 1000 не копится, бейдж должен показать 1000, не 1200.
     */
    public function test_periodic_consumable_shows_held_as_last_issuance(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->periodicConsumableRequirement();

        $this->issuePeriodic($p, $r, $k, '200', '2026-06-01', 'ml');
        $this->issuePeriodic($p, $r, $k, '1000', '2026-09-01', 'ml');

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('на руках 1000 / 1200 мл', $html, 'крем — количество показано, held = последняя выдача (1000), не сумма (1200)');
    }

    /**
     * Task 4: инструктаж (периодическая, норма 1 шт) — количество/«на руках» на `/issue` НЕ показывается
     * (норма 1 шт. технического смысла не несёт); «выдано»/«след. срок» остаются видны.
     */
    public function test_periodic_instruction_hides_quantity(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->periodicInstructionRequirement();
        $this->issuePeriodic($p, $r, $k, '1', '2026-06-01', 'pcs');

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringNotContainsString('на руках', $html, 'инструктаж: «на руках» не показывается вовсе');
        self::assertStringNotContainsString('Норма', $html, 'инструктаж: норма (1 шт.) в мета-строке не показывается');
        self::assertStringContainsString('01.06.2026', $html, 'дата последней выдачи (прошёл инструктаж) всё равно видна');
    }

    /** @return array{profileId: string, requirementId: string, key: string} Периодическое требование: крем, норма 1200 мл, 1 раз в год. */
    private function periodicConsumableRequirement(): array
    {
        return $this->periodicRequirement('Крем защитный', '1200', 'ml');
    }

    /** @return array{profileId: string, requirementId: string, key: string} Периодическое требование-инструктаж: норма 1 шт, 1 раз в год. */
    private function periodicInstructionRequirement(): array
    {
        return $this->periodicRequirement('Вводный инструктаж', '1', 'pcs');
    }

    /** @return array{profileId: string, requirementId: string, key: string} */
    private function periodicRequirement(string $itemLabel, string $amount, string $unit): array
    {
        $bus = $this->client->getContainer()->get(CommandBusInterface::class);

        $pos = $bus->execute(new CreatePositionCommand('Маляр '.uniqid('', true)));
        \assert($pos instanceof CreatePositionCommandResult);
        $org = $bus->execute(new CreateCounterpartyCommand('ООО Тест '.uniqid('', true), $this->randomTin()));
        \assert($org instanceof CreateCounterpartyCommandResult);
        $dept = $bus->execute(new CreateDepartmentCommand($org->id, 'Цех '.uniqid('', true)));
        \assert($dept instanceof CreateDepartmentCommandResult);
        $profile = $bus->execute(new CreateProfileCommand(
            userUlid: UuidService::generateUlid(),
            lastName: 'Петров', firstName: 'Пётр', middleName: 'Петрович',
            positionId: $pos->id, organizationId: $org->id, departmentId: $dept->id,
            personnelNumber: 'PN-'.uniqid('', true), hiredAt: new \DateTimeImmutable('2024-01-15'),
            clothing: null, shoes: null, headgear: null, respirator: null, gloves: null, height: null, gender: null,
        ));
        \assert($profile instanceof CreateProfileCommandResult);

        $req = $bus->execute(new SaveRequirementCommand(
            null, 'Периодическая выдача', 'periodic', [$pos->id],
            [['label' => $itemLabel, 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => $amount, 'unit' => $unit, 'basis' => 'п.9']],
        ));
        \assert($req instanceof SaveRequirementCommandResult);
        $this->client->getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profile->id));

        return ['profileId' => $profile->id, 'requirementId' => $req->id, 'key' => $this->obligationKeyFor($req->id, $itemLabel)];
    }

    /** Прямая запись факта периодического требования в журнал (без черновика/скана/подписи) + пересборка проекции. */
    private function issuePeriodic(string $profileId, string $requirementId, string $key, string $amount, string $date, string $unit): void
    {
        $this->client->getContainer()->get(CommandBusInterface::class)->execute(new RecordIssuanceCommand(
            $profileId, $requirementId, $date,
            [['obligationKey' => $key, 'amount' => $amount, 'unit' => $unit]],
            cardNumber: 'CARD-PERIODIC',
        ));
        $this->client->getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));
        $this->client->getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function issueUrl(string $profileId, string $requirementId): string
    {
        return sprintf('/cabinet/compliance/person/%s/requirement/%s/issue', $profileId, $requirementId);
    }
}
