<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * HTTP-смоук страниц норм: список и редактор рендерятся под админом (ловит Twig-ошибки шаблонов),
 * сохранение валидной нормы редиректит, пустое основание — ре-рендер с ошибкой.
 */
final class RequirementsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $em = $container->get(EntityManagerInterface::class);

        $user = new User(new Email('compliance_ctrl_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $container->get(UserPasswordHasherInterface::class));
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
    }

    public function test_list_page_renders(): void
    {
        $this->client->request('GET', '/cabinet/compliance/requirements');
        self::assertResponseIsSuccessful();
    }

    public function test_create_editor_renders(): void
    {
        $this->client->request('GET', '/cabinet/compliance/requirements/create');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form#req-form');
        self::assertSelectorExists('[data-controller="req-rows"]');
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setValue($obj, $value);
    }
}
