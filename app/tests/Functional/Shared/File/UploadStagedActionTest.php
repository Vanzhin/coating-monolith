<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\File;

use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadStagedActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $userUlid;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);

        $this->userEmail = 'file_stage_'.uniqid('', true).'@example.com';
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();
        $this->userUlid = $user->getUlid();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $c = static::getContainer();
        $em = $c->get(EntityManagerInterface::class);
        $storage = $c->get(FileStorage::class);

        try {
            $staged = $em->getRepository(StoredFile::class)->findBy(['uploaderId' => $this->userUlid]);
            foreach ($staged as $file) {
                $storage->remove($file->id());
            }
            $user = $em->find(User::class, $this->userUlid);
            if (null !== $user) {
                $em->remove($user);
                $em->flush();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_stages_uploaded_file_and_returns_uuid(): void
    {
        $this->client->request('POST', '/cabinet/file/stage', [], ['files' => [$this->upload($this->pngBytes(), 'p.png')]]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        // Глобальный враппер оборачивает JsonResponse под ключ data.
        self::assertNotEmpty($data['data']['files'][0]['uuid']);
        self::assertSame('p.png', $data['data']['files'][0]['name']);
        self::assertSame('image/png', $data['data']['files'][0]['mime']);
    }

    public function test_rejects_disallowed_mime(): void
    {
        $this->client->request('POST', '/cabinet/file/stage', [], ['files' => [$this->upload('just text', 'n.txt')]]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        // Сообщение об ошибке должно доехать: глобальный враппер на 4xx читает ключ `message`.
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('error', $data['result']);
        self::assertNotEmpty($data['message']);
    }

    public function test_anonymous_request_is_rejected(): void
    {
        self::ensureKernelShutdown();
        $anon = static::createClient();
        $anon->request('POST', '/cabinet/file/stage', [], ['files' => [$this->upload($this->pngBytes(), 'p.png')]]);

        // Незалогиненный упирается в firewall (^/cabinet → IS_AUTHENTICATED): редирект на логин.
        self::assertTrue(
            $anon->getResponse()->isRedirect() || 401 === $anon->getResponse()->getStatusCode(),
            'Аноним не должен попадать на endpoint загрузки',
        );
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up_');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setAccessible(true);
        $ref->setValue($obj, $value);
    }
}
