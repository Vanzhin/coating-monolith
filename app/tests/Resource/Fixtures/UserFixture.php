<?php

declare(strict_types=1);

namespace App\Tests\Resource\Fixtures;

use App\Tests\Resource\Tools\FakerTools;
use App\Users\Domain\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class UserFixture extends Fixture
{
    use FakerTools;

    public function __construct(private readonly UserFactory $userFactory)
    {
    }

    public const REFERENCE = 'user';

    public function load(ObjectManager $manager): void
    {
        $email = $this->getFaker()->email();
        $password = $this->getFaker()->password();
        $user = $this->userFactory->create($email, $password);
        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::REFERENCE, $user);
    }
}
