<?php

namespace App\Tests\Functional\Users\Infrastructure\Repository;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Factory\UserFactory;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use App\Users\Infrastructure\Repository\UserRepository;
use Faker\Factory;
use Faker\Generator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserRepositoryTest extends WebTestCase
{
    private UserRepository $repository;
    private Generator $faker;
    private UserFactory $userFactory;

    public function setUp(): void
    {
        parent::setUp();
        $client = static::createClient();
        $this->repository = $client->getContainer()->get(UserRepository::class);
        $this->userFactory = $client->getContainer()->get(UserFactory::class);
        $this->faker = Factory::create();
    }

    public function test_user_added_successfully(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get('doctrine.orm.entity_manager');
        
        // arrange
        $email = $this->faker->email();
        $password = $this->faker->password();
        $user = $this->userFactory->create($email, $password);

        // act
        $this->repository->add($user);

        // assert
        $existedUser = $this->repository->getByUlid($user->getUlid());
        $this->assertEquals($user->getUlid(), $existedUser->getUlid());
        
        // Очищаем базу
        $entityManager->remove($user);
        $entityManager->flush();
    }

    public function test_user_found_successfully(): void
    {
        $client = static::createClient();
        $entityManager = $client->getContainer()->get('doctrine.orm.entity_manager');
        
        // arrange - создаём пользователя программно
        $user = new User(new Email('test@example.com'));
        $hasher = $client->getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword('password123', $hasher);
        
        $entityManager->persist($user);
        $entityManager->flush();

        // act
        $existedUser = $this->repository->getByUlid($user->getUlid());

        // assert
        $this->assertEquals($user->getUlid(), $existedUser->getUlid());
        
        // Очищаем базу
        $entityManager->remove($user);
        $entityManager->flush();
    }
}
