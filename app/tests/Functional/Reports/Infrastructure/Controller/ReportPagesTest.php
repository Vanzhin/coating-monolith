<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Infrastructure\Controller;

use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Смоук страниц отчётов: список / форма создания рендерятся, создание ведёт на просмотр.
 * Проверяет рантайм-склейку шаблонов (list_page + infinite_list + форма) под залогиненным юзером.
 */
final class ReportPagesTest extends WebTestCase
{
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
        parent::tearDown();
    }

    public function test_list_page_renders(): void
    {
        $this->client->request('GET', '/cabinet/report');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Отчёты');
    }

    public function test_create_form_renders(): void
    {
        $this->client->request('GET', '/cabinet/report/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="type"]');
    }

    public function test_create_redirects_to_view(): void
    {
        $this->client->request('POST', '/cabinet/report/new', [
            'type' => 'trial_application',
            'actNumber' => 'SMOKE-01',
        ]);
        self::assertResponseRedirects();

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#/cabinet/report/[0-9a-f-]{36}$#', $location);
        $this->reportIds[] = substr($location, strrpos($location, '/') + 1);

        $this->client->request('GET', $location);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'SMOKE-01');
    }

    public function test_fill_form_renders_and_saves(): void
    {
        $this->client->request('POST', '/cabinet/report/new', ['type' => 'trial_application', 'actNumber' => 'FILL-01']);
        $id = substr((string) $this->client->getResponse()->headers->get('Location'), -36);
        $this->reportIds[] = $id;

        $this->client->request('GET', '/cabinet/report/'.$id.'/fill');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form textarea, form input, form select');

        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', [
            'action' => 'save',
            'content' => [
                'control_area' => ['description' => 'Балка Б-1'],
                'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½'],
                'conclusion' => ['text' => 'ок'],
            ],
        ]);
        self::assertResponseRedirects('/cabinet/report/'.$id);
    }

    public function test_download_generates_docx(): void
    {
        $this->client->request('POST', '/cabinet/report/new', ['type' => 'trial_application', 'actNumber' => 'DL-1']);
        $id = substr((string) $this->client->getResponse()->headers->get('Location'), -36);
        $this->reportIds[] = $id;

        $this->client->request('GET', '/cabinet/report/'.$id.'/download');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('wordprocessingml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertNotSame('', (string) $this->client->getResponse()->getContent());
    }

    public function test_submit_then_reviewer_approves(): void
    {
        $this->client->request('POST', '/cabinet/report/new', ['type' => 'trial_application', 'actNumber' => 'RV-1']);
        $id = substr((string) $this->client->getResponse()->headers->get('Location'), -36);
        $this->reportIds[] = $id;

        // Заполняем обязательное и сразу отправляем на проверку.
        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', [
            'action' => 'submit',
            'content' => [
                'control_area' => ['description' => 'Балка Б-1'],
                'surface_prep' => ['rustGrade' => 'B', 'prepDegree' => 'Sa 2½'],
                'conclusion' => ['text' => 'соответствует'],
            ],
        ]);
        self::assertResponseRedirects('/cabinet/report/'.$id);

        // Ревьюер (админ) утверждает.
        $this->client->request('POST', '/cabinet/report/'.$id.'/approve');
        self::assertResponseRedirects('/cabinet/report/'.$id);

        $this->client->request('GET', '/cabinet/report/'.$id);
        self::assertSelectorTextContains('body', 'Утверждён');
    }

    public function test_edit_header_renders_and_updates(): void
    {
        $this->client->request('POST', '/cabinet/report/new', ['type' => 'trial_application', 'actNumber' => 'ED-1']);
        $id = substr((string) $this->client->getResponse()->headers->get('Location'), -36);
        $this->reportIds[] = $id;

        $this->client->request('GET', '/cabinet/report/'.$id.'/edit');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="actNumber"]');

        $this->client->request('POST', '/cabinet/report/'.$id.'/edit', ['actNumber' => 'ED-2', 'reportDate' => '2026-08-05']);
        self::assertResponseRedirects('/cabinet/report/'.$id);

        $this->client->request('GET', '/cabinet/report/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'ED-2');
    }

    public function test_quick_create_counterparty_then_project(): void
    {
        $this->client->request('POST', '/cabinet/reports/counterparty/quick', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode(['title' => 'QuickCP-'.uniqid('', true)]));
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

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
