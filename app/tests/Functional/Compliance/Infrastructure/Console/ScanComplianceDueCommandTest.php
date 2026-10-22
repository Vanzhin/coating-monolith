<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Console;

use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Команда прохода сроков: без флагов — все состояния рассылки (дефолт), булев флаг на бакет сужает прогон
 * до подмножества (например, только --overdue, тогда Missing «не выдано» в этот прогон не идёт).
 */
final class ScanComplianceDueCommandTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_default_scope_covers_all_buckets(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $this->enrollCompliance(); // не выдано → Missing

        $tester = $this->commandTester();
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('все состояния', $tester->getDisplay());
    }

    public function test_bucket_flag_narrows_run_and_skips_missing(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $this->enrollCompliance(); // не выдано → Missing

        $tester = $this->commandTester();
        $tester->execute(['--overdue' => true]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('overdue', $display);
        self::assertStringContainsString('Дайджестов отправлено: 0', $display);
    }

    private function commandTester(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel))->find('app:compliance:scan-due'));
    }
}
