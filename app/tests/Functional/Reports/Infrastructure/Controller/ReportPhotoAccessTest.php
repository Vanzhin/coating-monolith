<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Infrastructure\Controller;

use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Отдача файла (/cabinet/file/{uuid}) под доступом отчёта: фото видит владелец/админ, чужой — 403.
 */
final class ReportPhotoAccessTest extends WebTestCase
{
    use CoatingSystemLayerTestFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->setUpFixture($c, $this->em);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    public function test_owner_views_photo_stranger_gets_403(): void
    {
        $owner = $this->makeUser(['ROLE_ADMIN']); // админ = владелец (isManager)
        $this->client->loginUser($owner);

        // Создаём отчёт, стейджим фото, сохраняем контент с фото → promote (ownerId = reportId).
        $this->client->request('POST', '/cabinet/report/new', ['type' => 'trial_application', 'systemId' => (string) $this->systemId]);
        preg_match('~/cabinet/report/([0-9a-f-]{36})/fill~', (string) $this->client->getResponse()->headers->get('Location'), $m);
        $id = $m[1];

        $uuid = $this->stagePhoto();
        $this->client->request('POST', '/cabinet/report/'.$id.'/fill', [
            'action' => 'save',
            'systemId' => (string) $this->systemId,
            'content' => ['photos' => ['items' => [['file' => $uuid, 'caption' => 'фото']]]],
        ]);
        self::assertResponseRedirects('/cabinet/report/'.$id.'/fill');

        // Владелец/админ — 200 + картинка.
        $this->client->request('GET', '/cabinet/file/'.$uuid);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('image/', (string) $this->client->getResponse()->headers->get('Content-Type'));

        // Чужой (не админ, не владелец) — 403.
        $this->client->loginUser($this->makeUser(['ROLE_USER']));
        $this->client->request('GET', '/cabinet/file/'.$uuid);
        self::assertResponseStatusCodeSame(403);
    }

    /** @param list<string> $roles */
    private function makeUser(array $roles): User
    {
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User(new Email('photo_acl_'.uniqid('', true).'@example.com'));
        $user->setPassword('pw', $hasher);
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, $roles);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function stagePhoto(): string
    {
        $path = sys_get_temp_dir().'/acl_'.uniqid('', true).'.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true));
        $upload = new UploadedFile($path, 'photo.png', 'image/png', null, true);
        $this->client->request('POST', '/cabinet/file/stage', [], ['files' => [$upload]]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'] ?? [];
        @unlink($path);

        return $data['files'][0]['uuid'] ?? '';
    }
}
