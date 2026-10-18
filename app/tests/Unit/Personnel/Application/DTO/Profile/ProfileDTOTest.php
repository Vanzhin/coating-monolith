<?php

declare(strict_types=1);

namespace App\Tests\Unit\Personnel\Application\DTO\Profile;

use App\Personnel\Application\DTO\Profile\ProfileDTO;
use PHPUnit\Framework\TestCase;

final class ProfileDTOTest extends TestCase
{
    public function test_fio_joins_last_first_middle_name(): void
    {
        $profile = new ProfileDTO();
        $profile->lastName = 'Иванов';
        $profile->firstName = 'Иван';
        $profile->middleName = 'Иванович';

        self::assertSame('Иванов Иван Иванович', $profile->fio());
    }

    public function test_fio_without_middle_name_has_no_trailing_space(): void
    {
        $profile = new ProfileDTO();
        $profile->lastName = 'Иванов';
        $profile->firstName = 'Иван';
        $profile->middleName = null;

        self::assertSame('Иванов Иван', $profile->fio());
    }
}
