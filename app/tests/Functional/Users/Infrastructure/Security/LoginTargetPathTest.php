<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Security;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Возврат на исходную страницу после парольного входа через встроенный _target_path.
 * Ссылка «Войти» с публичной страницы несёт свой URL → после входа возвращаемся туда;
 * внешний/protocol-relative путь отвергается (open-redirect guard) → кабинет.
 */
final class LoginTargetPathTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $email;
    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = bin2hex(random_bytes(4));
        $this->email = 'test_login_target_'.$suffix.'@example.com';
        $this->password = 'test_password_'.$suffix;

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->email));
        $user->setPassword($this->password, $hasher);
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($user, true);
        $this->em->persist($user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->email]);
            if (null !== $user) {
                $em->remove($user);
                $em->flush();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_password_login_returns_to_local_target_path(): void
    {
        $crawler = $this->client->request('GET', '/login', ['_target_path' => '/tools/mix']);
        $form = $crawler->filter('form')->form([
            'email' => $this->email,
            'password' => $this->password,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/tools/mix');
    }

    public function test_protocol_relative_target_path_is_rejected(): void
    {
        $crawler = $this->client->request('GET', '/login', ['_target_path' => '//evil.example.com']);
        $form = $crawler->filter('form')->form([
            'email' => $this->email,
            'password' => $this->password,
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/cabinet');
    }
}
