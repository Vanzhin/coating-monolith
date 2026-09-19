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

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
