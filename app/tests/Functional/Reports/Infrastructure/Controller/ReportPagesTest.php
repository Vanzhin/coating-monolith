<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Infrastructure\Controller;

use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Смоук страниц отчётов: список с модалкой создания; создание (вид+система) ведёт на заполнение;
 * реквизиты и блоки — на одной странице заполнения. Проверяет рантайм-склейку шаблонов под юзером.
 */
final class ReportPagesTest extends WebTestCase
{
    use CoatingSystemLayerTestFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $reportIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->setUpFixture($c, $this->em); // система обязательна: заводим одну (1 слой)

        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User(new Email('report_pages_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $hasher);
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $c = static::getContainer();
        $reports = $c->get(ReportRepositoryInterface::class);
        try {
            foreach ($this->reportIds as $id) {
                if (null !== ($r = $reports->findOneById($id))) {
                    $reports->remove($r);
                }
            }
            // Тест-юзера не удаляем: у него FK-хвост (user_channel), а эфемерная test_db и так сбрасывается.
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    public function test_list_page_renders(): void
    {
        $this->client->request('GET', '/cabinet/report');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Отчёты');
    }

    public function test_create_modal_present_on_list(): void
    {
        $this->client->request('GET', '/cabinet/report');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#reportCreateModal');
        self::assertSelectorExists('#reportCreateModal select[name="type"]');
    }

    public function test_create_redirects_to_fill(): void
    {
        $id = $this->createReport();

        $this->client->request('GET', '/cabinet/report/'.$id.'/fill');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="actNumber"]'); // реквизиты — на странице заполнения
    }

    public function test_fill_page_has_calculator_launchers(): void
    {
        $id = $this->createReport();

        $this->client->request('GET', '/cabinet/report/'.$id.'/fill');
        self::assertResponseIsSuccessful();
        // Значки-калькуляторы у полей слоёв (мокрая плёнка / точка росы) + их шторки на странице.
        self::assertSelectorExists('[data-controller="calc-launcher"]');
        self::assertSelectorExists('#wetFilmCalcModal');
        self::assertSelectorExists('#dewPointCalcModal');
    }

    public function test_fill_form_renders_and_saves(): void
    {
        $id = $this->createReport();

        $this->client->request('GET', '/cabinet/report/'.$id.'/fill');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form textarea, form input, form select');

        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', [
            'action' => 'save',
            'actNumber' => 'FILL-01',
            'systemId' => (string) $this->systemId,
            'content' => [
                'control_area' => ['description' => 'Балка Б-1'],
                'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½'],
                'conclusion' => ['text' => ['ок']],
            ],
        ]);
        self::assertResponseRedirects('/cabinet/report/'.$id.'/fill');
    }

    public function test_requisites_saved_via_fill(): void
    {
        $id = $this->createReport();

        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', [
            'action' => 'save',
            'actNumber' => 'ED-2',
            'reportDate' => '2026-08-05',
            'address' => 'г. Березовский',
            'systemId' => (string) $this->systemId,
        ]);
        self::assertResponseRedirects('/cabinet/report/'.$id.'/fill');

        $this->client->request('GET', '/cabinet/report/'.$id.'/fill');
        self::assertResponseIsSuccessful();
        // Реквизиты теперь — значения инпутов формы, не текст.
        self::assertSelectorExists('input[name="actNumber"][value="ED-2"]');
        self::assertSelectorExists('input[name="address"][value="г. Березовский"]');
    }

    public function test_references_saved_via_fill(): void
    {
        $this->client->request('POST', '/cabinet/reports/counterparty/quick', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['title' => 'Заказчик-'.uniqid('', true), 'tin' => '2000000100']));
        $cp = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];

        $id = $this->createReport();
        // Ссылки + контент (слои) одним сохранением — как реальная форма.
        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', [
            'action' => 'save',
            'systemId' => (string) $this->systemId,
            'customerId' => $cp['id'],
            'customerTitle' => $cp['title'],
            'content' => ['application' => ['layers' => [['material' => (string) $this->coatingId]]]],
        ]);
        self::assertResponseRedirects('/cabinet/report/'.$id.'/fill');

        $this->client->request('GET', '/cabinet/report/'.$id.'/fill');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        // Сохранённый заказчик возвращается в форму как existing-value (id в разметке).
        self::assertStringContainsString($cp['id'], $html);
        // И слой нанесения сохранился (id покрытия в hidden coating_ref).
        self::assertStringContainsString((string) $this->coatingId, $html);
    }

    public function test_error_rerender_keeps_system_plan(): void
    {
        $id = $this->createReport();

        // Невалидная влажность (percent) → ошибка валидации → ре-рендер, а не редирект.
        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', [
            'action' => 'save',
            'systemId' => (string) $this->systemId,
            'content' => ['application' => ['layers' => [['material' => (string) $this->coatingId, 'humidity' => '-5']]]],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Процент'); // сообщение об ошибке показано
        // Блок «Система (план)» readOnly не в POST — но на ре-рендере берётся из отчёта, не пустой.
        self::assertStringNotContainsString('Система без слоёв', (string) $this->client->getResponse()->getContent());
    }

    public function test_download_generates_docx(): void
    {
        $id = $this->createReport();

        // Полная обязательная шапка + обязательные поля блоков + материал под шаблон.
        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', array_merge($this->fullRequisites(), [
            'action' => 'save',
            'content' => [
                'control_area' => ['description' => 'Балка Б-1'],
                'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½'],
                'conclusion' => ['text' => ['соответствует']],
                'application' => ['layers' => [['material' => (string) $this->coatingId]]],
            ],
        ]));

        $this->client->request('GET', '/cabinet/report/'.$id.'/download');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('wordprocessingml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNotSame('', (string) $this->client->getResponse()->getContent());
    }

    public function test_download_blocked_when_required_fields_missing(): void
    {
        $id = $this->createReport();

        // Только реквизиты, без обязательных полей блоков — гейт полноты по домену не пустит.
        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', [
            'action' => 'save',
            'actNumber' => 'DL-2',
            'systemId' => (string) $this->systemId,
            'content' => ['application' => ['layers' => [['material' => (string) $this->coatingId]]]],
        ]);

        $this->client->request('GET', '/cabinet/report/'.$id.'/download');
        self::assertResponseRedirects('/cabinet/report/'.$id.'/fill'); // не отдаёт файл — редирект с ошибкой
    }

    public function test_submit_then_reviewer_approves(): void
    {
        $id = $this->createReport();

        // Полная шапка + обязательные поля блоков → отправка на проверку.
        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', array_merge($this->fullRequisites(), [
            'action' => 'submit',
            'content' => [
                'control_area' => ['description' => 'Балка Б-1'],
                'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½'],
                'conclusion' => ['text' => ['соответствует']],
            ],
        ]));
        self::assertResponseRedirects('/cabinet/report/'.$id.'/fill');

        // Ревьюер (админ) утверждает.
        $this->client->request('POST', '/cabinet/report/'.$id.'/approve');
        self::assertResponseRedirects('/cabinet/report/'.$id.'/fill');

        $this->client->request('GET', '/cabinet/report/'.$id.'/fill');
        self::assertSelectorTextContains('body', 'Утверждён');
    }

    public function test_quick_create_counterparty_then_project(): void
    {
        $this->client->request('POST', '/cabinet/reports/counterparty/quick', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['title' => 'QuickCP-'.uniqid('', true), 'tin' => '3000000013']));
        self::assertResponseStatusCodeSame(201);
        // Глобальный ResponseListener оборачивает JSON в {data:{…}}.
        $cp = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        self::assertNotEmpty($cp['id']);

        // Проект без заказчика — 422.
        $this->client->request('POST', '/cabinet/reports/project/quick', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['title' => 'QuickPrj']));
        self::assertResponseStatusCodeSame(422);

        // Проект с заказчиком — 201.
        $this->client->request('POST', '/cabinet/reports/project/quick', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['title' => 'QuickPrj-'.uniqid('', true), 'counterpartyId' => $cp['id']]));
        self::assertResponseStatusCodeSame(201);
    }

    /**
     * Поля POST для ПОЛНОЙ обязательной шапки (создаёт заказчика/подрядчика/проект).
     *
     * @return array<string, mixed>
     */
    private function fullRequisites(): array
    {
        $customer = $this->quickCounterparty('Заказчик-'.uniqid('', true), '2000000082');
        $contractor = $this->quickCounterparty('Подрядчик-'.uniqid('', true), '2000000090');
        $project = $this->quickProject('Проект-'.uniqid('', true), $customer['id']);

        return [
            'actNumber' => 'AN-'.uniqid('', true),
            'reportDate' => '2026-09-21',
            'address' => 'г. Самара',
            'workFrom' => '2026-09-21',
            'workTo' => '2026-09-25',
            'systemId' => (string) $this->systemId,
            'customerId' => $customer['id'], 'customerTitle' => $customer['title'],
            'contractorId' => $contractor['id'], 'contractorTitle' => $contractor['title'],
            'projectId' => $project['id'], 'projectTitle' => $project['title'],
        ];
    }

    /** @return array{id: string, title: string} */
    private function quickCounterparty(string $title, string $tin): array
    {
        $this->client->request('POST', '/cabinet/reports/counterparty/quick', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['title' => $title, 'tin' => $tin]));

        return json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
    }

    /** @return array{id: string, title: string} */
    private function quickProject(string $title, string $counterpartyId): array
    {
        $this->client->request('POST', '/cabinet/reports/project/quick', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['title' => $title, 'counterpartyId' => $counterpartyId]));

        return json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
    }

    /** Создать отчёт через POST (вид+система) и вернуть его id (редирект ведёт на страницу заполнения). */
    private function createReport(): string
    {
        $this->client->request('POST', '/cabinet/report/new', [
            'type' => 'trial_application',
            'systemId' => (string) $this->systemId,
        ]);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/cabinet/report/[0-9a-f-]{36}/fill$#', $location);
        preg_match('#/report/([0-9a-f-]{36})/fill#', $location, $m);
        $this->reportIds[] = $m[1];

        return $m[1];
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
