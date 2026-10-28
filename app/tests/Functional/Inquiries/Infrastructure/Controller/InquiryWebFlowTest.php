<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class InquiryWebFlowTest extends WebTestCase
{
    public function test_create_shows_received_card(): void
    {
        $client = $this->loggedInAdminClient();

        $crawler = $client->request('GET', '/cabinet/inquiry/new');
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/cabinet/inquiry', [
            'text' => 'нужна антикоррозионная система для этанола, погружение, сталь',
            '_token' => $token,
        ]);
        self::assertResponseStatusCodeSame(302);

        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'идёт подбор');
    }

    public function test_full_text_fragment_shows_body(): void
    {
        $client = $this->loggedInAdminClient();

        $crawler = $client->request('GET', '/cabinet/inquiry/new');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $client->request('POST', '/cabinet/inquiry', ['text' => 'МАРКЕР-ПОЛНОГО-ТЕКСТА', '_token' => $token]);
        $location = (string) $client->getResponse()->headers->get('Location');

        $client->request('GET', $location.'/text');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-body', 'МАРКЕР-ПОЛНОГО-ТЕКСТА');
    }

    private function loggedInAdminClient(): KernelBrowser
    {
        self::ensureKernelShutdown();
        $client = static::createClient();
        $container = $client->getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        \assert($hasher instanceof UserPasswordHasherInterface);

        $user = new User(new Email('inq_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $hasher);
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $client;
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty($object, $property);
        $ref->setValue($object, $value);
    }
}
