<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Application\DTO\ProfileCompliance\ObligationRowDTO;
use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\RecordIssuance\RecordIssuanceCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQuery;
use App\Compliance\Application\UseCase\Query\GetProfileCompliance\GetProfileComplianceQueryResult;
use App\Compliance\Domain\Aggregate\ProfileCompliance\FulfillmentRecord;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\ValueObject\ObligationKey;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommand;
use App\Personnel\Application\UseCase\Command\CreateDepartment\CreateDepartmentCommandResult;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommand;
use App\Personnel\Application\UseCase\Command\CreatePosition\CreatePositionCommandResult;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommandResult;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommand;
use App\Reports\Application\UseCase\Command\CreateCounterparty\CreateCounterpartyCommandResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
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
     * Compliance soft-delete §40-43: позицию мягко убрали из нормы требования, но она уже выдана (held>0) —
     * на /issue она обязана остаться видимой строкой «только списать» (имя, не сырой ключ; чекбокс выбора,
     * без полей выдачи), чтобы попасть в «Списать выбранные»/в уже открытый черновик акта. Активная позиция
     * той же нормы рендерится как обычно (с полем количества выдачи).
     */
    public function test_removed_but_held_position_renders_as_writeoff_only_row_without_issuance_fields(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $glovesKey] = $this->enrollCompliance('Перчатки', extraItems: [
            ['label' => 'Ботинки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '2', 'unit' => 'pair', 'basis' => 'п.5'],
        ]);
        $bootsKey = $this->obligationKeyFor($r, 'Ботинки');
        $this->issueCard($p, $r, $glovesKey);
        $this->issueCard($p, $r, $bootsKey, '2');
        $this->softRemoveItemKeepingOthers($p, $r, 'Перчатки');

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Ботинки', $html, 'имя удалённой-но-выданной позиции видно на /issue');
        self::assertStringContainsString('удалено из нормы', $html, 'маркер отличает её от активной нормы');

        $checkbox = $crawler->filter('input[data-writeoff-only="1"]');
        self::assertGreaterThan(0, $checkbox->count(), 'чекбокс выбора у строки «только списать» присутствует');
        self::assertSame('2', $checkbox->first()->attr('data-held'), 'на руках — из факта журнала');

        $card = $checkbox->first()->closest('.bg-body');
        self::assertNotNull($card);
        self::assertStringContainsString('Ботинки', $card->text());
        self::assertSame(0, $card->filter('input[type="number"]')->count(), 'строка «только списать» не несёт полей выдачи');

        // Активная позиция нормы («Перчатки») рендерится как обычно — с полем количества выдачи.
        self::assertGreaterThan(0, $crawler->filter('input[name$="[amount]"]')->count(), 'активная позиция сохранила поле выдачи');
    }

    /**
     * T6: /issue — экран ТОЛЬКО действий (выдать/списать). Позиция, мягко убранная из нормы, но держащая held>0,
     * обязана рендериться ТОЙ ЖЕ ПОЛНОЙ карточкой, что активные (периодичность/норма/последняя выдача, «на руках»),
     * просто ЗАМОРОЖЕННОЙ: замок вместо статус-бейджа, без «Восстановить» (это действие нормы — только в редакторе
     * требования, см. RequirementsControllerTest). Регрессия §40-43: раньше это была минимальная строка-заглушка.
     */
    public function test_removed_but_held_position_renders_as_full_frozen_card_not_a_stub(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $glovesKey] = $this->enrollCompliance('Перчатки', extraItems: [
            ['label' => 'Ботинки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '2', 'unit' => 'pair', 'basis' => 'п.5'],
        ]);
        $bootsKey = $this->obligationKeyFor($r, 'Ботинки');
        $this->issueCard($p, $r, $glovesKey);
        $this->issueCard($p, $r, $bootsKey, '2');
        $this->softRemoveItemKeepingOthers($p, $r, 'Перчатки');

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();

        $checkbox = $crawler->filter('input[data-writeoff-only="1"]');
        self::assertGreaterThan(0, $checkbox->count());
        $card = $checkbox->first()->closest('.bg-body');
        self::assertNotNull($card);

        self::assertStringContainsString('bi-lock', $card->outerHtml(), 'замок вместо статус-бейджа');
        self::assertStringContainsString('ежегодно', $card->text(), 'периодичность — как у активной позиции (full card, не заглушка)');
        self::assertStringContainsString('на руках 2 / 2 пара', $card->text(), '«на руках» несёт норму, как у активной позиции');
        self::assertStringNotContainsString('Восстановить', $card->text(), '«Восстановить» на /issue нет — это действие нормы (редактор требования)');
        self::assertSame(0, $card->filter('input[type="number"]')->count(), 'поля выдачи отсутствуют — заморожена');
        self::assertSame(0, $card->filter('[data-issue-fields]')->count(), 'раскрытия полей выдачи нет — выдавать удалённую нельзя');
        self::assertStringContainsString('История', $card->text(), 'ленивая история позиции остаётся — по журналу решают, что списывать');
        self::assertGreaterThan(0, $card->filter('[data-controller="obligation-history"]')->count(), 'тоггл истории позиции присутствует и у замороженной карточки');
    }

    /**
     * Пере-сохраняет требование, оставляя в форме РОВНО одну позицию ($keepLabel, с её текущим id — чтобы merge
     * обновил её на месте) — остальные позиции отсутствуют среди присланных и становятся removed-надгробиями
     * ({@see \App\Compliance\Domain\Aggregate\Requirement\Requirement::mergeItems}).
     */
    private function softRemoveItemKeepingOthers(string $profileId, string $requirementId, string $keepLabel): void
    {
        $requirement = $this->client->getContainer()->get(RequirementRepositoryInterface::class)->findOneById($requirementId);
        self::assertNotNull($requirement);
        $keepId = null;
        foreach ($requirement->getItems() as $item) {
            if ($item->label() === $keepLabel) {
                $keepId = $item->id();
            }
        }
        self::assertNotNull($keepId, sprintf('Позиция «%s» не найдена в требовании.', $keepLabel));

        $result = $this->client->getContainer()->get(CommandBusInterface::class)->execute(new SaveRequirementCommand(
            $requirementId, 'Личная карточка учёта выдачи СИЗ', 'material', $requirement->getPositionIds()->getList(),
            [['id' => $keepId, 'label' => $keepLabel, 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
        ));
        \assert($result instanceof SaveRequirementCommandResult);

        $this->client->getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));
        $this->client->getContainer()->get(EntityManagerInterface::class)->clear();
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

    /**
     * Task 4b (регрессия): периодический расходник (крем) — выдача стала типом `periodic` (раньше был
     * `material`) и потеряла поле «Кол-во»/«Ед.» на /issue (гейт был `o.type == 'material'`); без поля
     * выдать с количеством нельзя → held=0 → вечный Missing. Поле должно вернуться, единица — зафиксирована
     * нормой (disabled select + hidden input), как у материала.
     */
    public function test_periodic_consumable_shows_amount_input_for_issuance(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->periodicConsumableRequirement();

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('input[name="items[0][amount]"]')->count(), 'периодический расходник: поле количества есть');
        $unitInput = $crawler->filter('input[name="items[0][unit]"]')->first();
        self::assertSame('ml', $unitInput->attr('value'), 'единица зафиксирована нормой требования (ml), скрытым полем');
        self::assertGreaterThan(0, $crawler->filter('select[disabled][aria-label="Единица измерения"]')->count(), 'выбор единицы недоступен — она из нормы, не вводится руками');
    }

    /** Task 4b: инструктаж (периодическая, норма 1 шт) поля количества не несёт вовсе — только статус/даты. */
    public function test_periodic_instruction_has_no_amount_input(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->periodicInstructionRequirement();

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();

        self::assertSame(0, $crawler->filter('input[name="items[0][amount]"]')->count(), 'инструктаж: поля количества нет');
    }

    /**
     * Task 4b review-fix: добавляемая вне нормы позиция (`personal_row`) гейтит «Кол-во»/«Ед.» ТРЕБОВАНИЕМ
     * (material/periodic несёт количество — {@see \App\Compliance\Domain\Type\ComplianceType::requiresQuantity()}),
     * не схемой журнала инструктажа. Периодическое требование легально существует БЕЗ JournalKind (как
     * {@see self::periodicInstructionRequirement()} — её журнал пуст), и поле «Кол-во» всё равно должно быть
     * доступно для вне-нормовой позиции (админ сам решает, заполнять её или нет).
     */
    public function test_personal_row_shows_amount_input_under_periodic_requirement_without_journal_kind(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->periodicInstructionRequirement();

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('name="personalItems[__i__][amount]"', $html, 'периодическое требование без JournalKind: вне-нормовая позиция всё равно несёт ввод количества');
    }

    /**
     * Finding 1 (критический): инструктаж контролируется ПО ДАТЕ — после прохождения бакет обязан стать Ok, а
     * не оставаться Missing навсегда. Баг: амаунт-поле у инструктажа на форме скрыто
     * ({@see \App\Compliance\Application\UseCase\Query\GetIssuePage\GetIssuePageQueryHandler} `showAmountInput`
     * false для isInstructionLike), поэтому реальный сабмит формы НЕ шлёт `amount` → проектор держит held=0
     * ({@see \App\Compliance\Application\Service\ComplianceObligationProjector::computeFromFacts} — периодическая
     * держит количество ПОСЛЕДНЕЙ выдачи) → старый резолвер читал это как недовыдачу для ЛЮБОЙ периодической →
     * вечный Missing. Регрессия ловится только реальным POST формы — {@see self::issuePeriodic} (используемый
     * другими тестами этого файла) шлёт amount ЯВНО через `RecordIssuanceCommand`, это бы баг не поймало.
     */
    public function test_submit_real_form_for_instruction_without_amount_becomes_ok_not_missing_forever(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->periodicInstructionRequirement();

        $crawler = $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        self::assertSame(0, $crawler->filter('input[name="items[0][amount]"]')->count(), 'инструктаж: поля количества нет — форму сабмитим как есть, без amount');

        // Дефицитная (ни разу не пройденная) позиция отмечена чекбоксом по умолчанию — сабмитим форму без правок,
        // как реально делает браузер (нет JS, который мог бы что-то дописать).
        $this->client->submit($crawler->filter('#issue-form')->form());
        self::assertResponseRedirects();

        $this->client->request('GET', $this->issueUrl($p, $r));
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('в норме', $html, 'инструктаж пройден (held=0 — количество для него не инвариант) — бакет Ok');
        self::assertStringNotContainsString('не пройдено', $html, 'больше не красный Missing после прохождения');
    }

    /**
     * Finding 1 (продуктово одобрено): позиция «вне нормы» на смешанной «Выдаче» больше не наследует тип
     * контейнера жёстко — у неё свой выбор «Вид» в форме. Периодический ad-hoc расходник обязан вести себя
     * как расходник: проекция держит его периодической позицией (не копит остаток, см.
     * {@see \App\Compliance\Application\Service\ComplianceObligationProjector::computeFromFacts}) и он не
     * подлежит списанию ({@see \App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance::writeOffEligibleFactsForRequirement}
     * — структурно только инвентарь).
     */
    public function test_adhoc_personal_item_submitted_as_periodic_is_not_writeoff_eligible(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance(); // material «Выдача» — допускает смешение

        $this->submitAdHocPersonalItem($p, $r, 'Крем защитный (вне нормы)', 'periodic', '200', 'ml');
        $key = $this->personalObligationKeyFor($p, $r, 'Крем защитный (вне нормы)');

        $row = $this->obligationRowFor($p, $r, $key);
        self::assertSame('periodic', $row->type, 'вне-нормовая позиция записана как расходник (periodic), не инвентарь — проекция не будет копить остаток');

        self::assertNotContains($key, $this->writeOffEligibleKeys($p, $r), 'расходник вне нормы не подлежит списанию');
    }

    /** Finding 1: явный «Инвентарь» или отсутствующее поле (дефолт — тип требования, тут material) — позиция списанию подлежит. */
    public function test_adhoc_personal_item_submitted_as_material_is_writeoff_eligible(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance();

        $this->submitAdHocPersonalItem($p, $r, 'Каска защитная (вне нормы)', 'material', '1', 'pcs');
        $key = $this->personalObligationKeyFor($p, $r, 'Каска защитная (вне нормы)');

        $row = $this->obligationRowFor($p, $r, $key);
        self::assertSame('material', $row->type);
        self::assertContains($key, $this->writeOffEligibleKeys($p, $r), 'инвентарная позиция вне нормы подлежит списанию наравне с норменной');
    }

    /** Finding 1: поле «Вид» не прислано вовсе (старая форма/снятый выбор) — хендлер дефолтит в тип требования. */
    public function test_adhoc_personal_item_without_type_field_defaults_to_requirement_type(): void
    {
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance(); // контейнер — material

        $this->submitAdHocPersonalItem($p, $r, 'Очки защитные (вне нормы)', null, '1', 'pcs');
        $key = $this->personalObligationKeyFor($p, $r, 'Очки защитные (вне нормы)');

        $row = $this->obligationRowFor($p, $r, $key);
        self::assertSame('material', $row->type, 'тип не прислан — дефолт тип требования (material)');
    }

    /**
     * Сабмит реальной формы `/issue` ровно с одной позицией «вне нормы» (норменные позиции не отмечаем —
     * проверяем только материализацию ad-hoc позиции). `$type` — значение поля `personalItems[0][type]`,
     * null → поле не отправляем вовсе (хендлер дефолтит в тип требования).
     */
    private function submitAdHocPersonalItem(string $profileId, string $requirementId, string $label, ?string $type, string $amount, string $unit): void
    {
        $crawler = $this->client->request('GET', $this->issueUrl($profileId, $requirementId));
        self::assertResponseIsSuccessful();
        $token = (string) $crawler->filter('#issue-form input[name="_csrf_token"]')->attr('value');

        $personalRow = ['label' => $label, 'amount' => $amount, 'unit' => $unit, 'manualDueDate' => '2026-12-31'];
        if (null !== $type) {
            $personalRow['type'] = $type;
        }
        $this->client->request('POST', $this->issueUrl($profileId, $requirementId), [
            '_csrf_token' => $token,
            'documentDate' => '2026-06-01',
            'cardNumber' => 'CARD-ADHOC',
            'personalItems' => [$personalRow],
        ]);
        self::assertResponseRedirects();
        $this->client->getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));
        $this->client->getContainer()->get(EntityManagerInterface::class)->clear();
    }

    /** Ключ персональной (вне нормы) обязанности по наименованию — itemId генерится хендлером, заранее не известен. */
    private function personalObligationKeyFor(string $profileId, string $requirementId, string $label): string
    {
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($profileId);
        self::assertNotNull($pc);
        foreach ($pc->personalObligations() as $personal) {
            if ($personal->requirementId === $requirementId && $personal->label === $label) {
                return ObligationKey::keyOf($personal->requirementId, $personal->itemId);
            }
        }

        throw new \RuntimeException(sprintf('Персональная позиция «%s» не найдена.', $label));
    }

    private function obligationRowFor(string $profileId, string $requirementId, string $key): ObligationRowDTO
    {
        /** @var GetProfileComplianceQueryResult $result */
        $result = $this->client->getContainer()->get(QueryBusInterface::class)->execute(new GetProfileComplianceQuery($profileId));
        self::assertNotNull($result->compliance);
        foreach ($result->compliance->obligations as $row) {
            if ($row->requirementId === $requirementId && $row->key === $key) {
                return $row;
            }
        }

        throw new \RuntimeException('Строка проекции по ключу не найдена.');
    }

    /** @return list<string> Ключи фактов требования, годных к списанию ({@see \App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance::writeOffEligibleFactsForRequirement}). */
    private function writeOffEligibleKeys(string $profileId, string $requirementId): array
    {
        $requirement = $this->client->getContainer()->get(RequirementRepositoryInterface::class)->findOneById($requirementId);
        self::assertNotNull($requirement);
        $pc = $this->client->getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($profileId);
        self::assertNotNull($pc);

        return array_map(static fn (FulfillmentRecord $fact): string => $fact->obligationKey(), $pc->writeOffEligibleFactsForRequirement($requirement, null));
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
        ['positionId' => $positionId, 'profileId' => $profileId] = $this->createPositionAndProfile();

        $req = $bus->execute(new SaveRequirementCommand(
            null, 'Периодическая выдача', 'periodic', [$positionId],
            [['label' => $itemLabel, 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => $amount, 'unit' => $unit, 'basis' => 'п.9']],
        ));
        \assert($req instanceof SaveRequirementCommandResult);
        $this->client->getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($profileId));

        return ['profileId' => $profileId, 'requirementId' => $req->id, 'key' => $this->obligationKeyFor($req->id, $itemLabel)];
    }

    /** @return array{positionId: string, profileId: string} Должность+организация+отдел+профиль — общий сетап periodic-фикстур этого файла. */
    private function createPositionAndProfile(): array
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

        return ['positionId' => $pos->id, 'profileId' => $profile->id];
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
