<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Faker\Factory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GetUserActionTest extends WebTestCase
{
    public function test_get_user_action(): void
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
        $this->assertIsArray($loginResponse);
        $this->assertArrayHasKey('token', $loginResponse);

        $client->setServerParameter('HTTP_AUTHORIZATION', sprintf('Bearer %s', $loginResponse['token']));

        $client->request('GET', '/api/users/' . $user->getUlid());
        $userResponse = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($userResponse);
        $this->assertEquals($email, $userResponse['email']);
        $this->assertEquals($user->getUlid(), $userResponse['ulid']);
    }
}
