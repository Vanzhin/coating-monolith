<?php

namespace App\Tests\Functional\Users\Infrastructure\Repository;

use App\Tests\Resource\Fixtures\UserFixture;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Factory\UserFactory;
use App\Users\Infrastructure\Repository\UserRepository;
use Faker\Factory;
use Faker\Generator;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Liip\TestFixturesBundle\Services\DatabaseTools\AbstractDatabaseTool;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class UserRepositoryTest extends WebTestCase
{
    private UserRepository $repository;
    private Generator $faker;
    private AbstractDatabaseTool $databaseTool;
    private UserFactory $userFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->repository = static::getContainer()->get(UserRepository::class);
        $this->userFactory = static::getContainer()->get(UserFactory::class);
        $this->faker = Factory::create();
        $this->databaseTool = static::getContainer()->get(DatabaseToolCollection::class)->get();
    }

    public function test_user_added_successfully(): void
    {
        // arrange
        $email = $this->faker->email();
        $password = $this->faker->password();
        $user = $this->userFactory->create($email, $password);

        // act
        $this->repository->add($user);

        // assert
        $existedUser = $this->repository->getByUlid($user->getUlid());
        $this->assertEquals($user->getUlid(), $existedUser->getUlid());
    }

    public function test_user_found_successfully(): void
    {
        // arrange
        $executor = $this->databaseTool->loadFixtures([UserFixture::class]);
        /** @var User $user */
        $user = $executor->getReferenceRepository()->getReference(UserFixture::REFERENCE);

        // act
        $existedUser = $this->repository->getByUlid($user->getUlid());

        // assert
        $this->assertEquals($user->getUlid(), $existedUser->getUlid());
    }
}
