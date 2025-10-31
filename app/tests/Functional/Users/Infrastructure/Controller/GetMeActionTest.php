<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GetMeActionTest extends WebTestCase
{
    private User $user;

    public function test_get_me_action(): void
    {
        $client = static::createClient();
        
        // Создаём пользователя после создания клиента
        $this->user = new User(new Email('test@example.com'));
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        $this->user->setPassword('password123', $hasher);
        
        // Сохраняем в базу
        $entityManager = $client->getContainer()->get('doctrine.orm.entity_manager');
        $entityManager->persist($this->user);
        $entityManager->flush();

        $client->request('POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $this->user->getEmail(),
                'password' => 'password123',
            ])
        );
        $data = json_decode($client->getResponse()->getContent(), true);
        
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('token', $data['data']);
        $this->assertNotNull($data['data']['token']);

        $client->setServerParameter('HTTP_AUTHORIZATION', sprintf('Bearer %s', $data['data']['token']));

        // act
        $client->request('GET', '/api/users/me');
        // assert
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($this->user->getEmail(), $data['data']['email']);
        
        // Очищаем базу
        $entityManager->remove($this->user);
        $entityManager->flush();
    }
}
