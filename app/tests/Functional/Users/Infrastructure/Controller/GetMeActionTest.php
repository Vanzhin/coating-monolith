<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Faker\Factory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GetMeActionTest extends WebTestCase
{
    public function test_get_me_action(): void
    {
        $client = static::createClient();
        $container = $client->getContainer();
        $faker = Factory::create();

        $email = $faker->email();
        $password = $faker->password();

        $user = new User(new Email($email));
        $user->setPassword($password, $container->get(UserPasswordHasherInterface::class));

        $entityManager = $container->get('doctrine.orm.entity_manager');
        $entityManager->persist($user);
        $entityManager->flush();

        $client->request(
            'POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $email, 'password' => $password]),
        );

        $loginResponse = json_decode($client->getResponse()->getContent(), true);
        $this->assertIsArray($loginResponse, sprintf(
            'Login failed with status %d, body: %s',
            $client->getResponse()->getStatusCode(),
            $client->getResponse()->getContent(),
        ));
        // Ответы API обёрнуты в {result, status, data, message} — токен в data.token.
        $this->assertArrayHasKey('data', $loginResponse);
        $this->assertArrayHasKey('token', $loginResponse['data']);
        $this->assertNotEmpty($loginResponse['data']['token']);

        $client->setServerParameter('HTTP_AUTHORIZATION', sprintf('Bearer %s', $loginResponse['data']['token']));

        $client->request('GET', '/api/users/me');
        $meResponse = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($meResponse, sprintf(
            'GET /api/users/me: status=%d, location=%s, body=%s',
            $client->getResponse()->getStatusCode(),
            $client->getResponse()->headers->get('Location') ?? 'none',
            substr($client->getResponse()->getContent(), 0, 200),
        ));
        $this->assertArrayHasKey('data', $meResponse);
        $this->assertEquals($email, $meResponse['data']['email']);
    }
}
