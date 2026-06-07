<?php

namespace App\Tests\Functional\Users\Infrastructure\Repository;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Factory\UserFactory;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use App\Users\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Faker\Factory;
use Faker\Generator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserRepositoryTest extends WebTestCase
{
    private UserRepository $repository;
    private UserFactory $userFactory;
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $hasher;
    private Generator $faker;

    public function setUp(): void
    {
        parent::setUp();
        $client = static::createClient();
        $container = $client->getContainer();

        $this->repository = $container->get(UserRepository::class);
        $this->userFactory = $container->get(UserFactory::class);
        $this->entityManager = $container->get('doctrine.orm.entity_manager');
        $this->hasher = $container->get(UserPasswordHasherInterface::class);
        $this->faker = Factory::create();
    }

    public function test_user_added_successfully(): void
    {
        $user = $this->userFactory->create($this->faker->email(), $this->faker->password());

        $this->repository->add($user);

        $existedUser = $this->repository->getByUlid($user->getUlid());
        $this->assertEquals($user->getUlid(), $existedUser->getUlid());
    }

    public function test_user_found_successfully(): void
    {
        $user = new User(new Email($this->faker->email()));
        $user->setPassword($this->faker->password(), $this->hasher);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $existedUser = $this->repository->getByUlid($user->getUlid());
        $this->assertEquals($user->getUlid(), $existedUser->getUlid());
    }
}
