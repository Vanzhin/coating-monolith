<?php

declare(strict_types=1);

namespace App\Tests\Resource\Tools;

use App\Tests\Resource\Fixtures\UserFixture;
use App\Users\Domain\Entity\User;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Liip\TestFixturesBundle\Services\DatabaseTools\AbstractDatabaseTool;

trait FixtureTool
{
    public function getDataBaseTool(): AbstractDatabaseTool
    {
        return static::getContainer()->get(DatabaseToolCollection::class)->get();
    }

    public function loadUserFixture(): User
    {
        $executor = $this->getDataBaseTool()->loadFixtures([UserFixture::class]);

        return $executor->getReferenceRepository()->getReference(UserFixture::REFERENCE);
    }
}
