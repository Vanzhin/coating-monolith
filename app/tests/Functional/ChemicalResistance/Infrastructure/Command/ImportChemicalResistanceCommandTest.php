<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Infrastructure\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportChemicalResistanceCommandTest extends KernelTestCase
{
    private const FIXTURE_DOCX = __DIR__.'/../../../../Fixtures/ChemicalResistance/minimal.docx';

    private CommandTester $tester;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $kernel = static::$kernel;
        $application = new Application($kernel);
        $command = $application->find('coatings:chemical-resistance:import');
        $this->tester = new CommandTester($command);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function getFirstCoatingTitle(): string
    {
        $row = $this->em
            ->getConnection()
            ->fetchAssociative('SELECT id::text AS id, title FROM coatings_coating LIMIT 1');

        if (false === $row) {
            $this->markTestSkipped('No coatings in database; seed a coating first.');
        }

        return $row['title'];
    }

    public function test_dry_run_reports_counts_without_writing(): void
    {
        $title = $this->getFirstCoatingTitle();

        $exitCode = $this->tester->execute([
            'docx' => self::FIXTURE_DOCX,
            '--coating-title' => $title,
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exitCode, 'Command should exit with success');

        $output = $this->tester->getDisplay();
        self::assertStringContainsString('Импорт', $output, 'Output should contain "Импорт"');
        self::assertStringContainsString('(dry-run)', $output, 'Output should indicate dry-run mode');
        self::assertStringContainsString('created', $output, 'Output should report created counts');
    }

    public function test_unknown_coating_title_fails(): void
    {
        $exitCode = $this->tester->execute([
            'docx' => self::FIXTURE_DOCX,
            '--coating-title' => 'ПОКРЫТИЕ_КОТОРОГО_НЕТ_В_БД_12345',
        ]);

        self::assertSame(1, $exitCode, 'Command should exit with failure for unknown coating');

        $output = $this->tester->getDisplay();
        self::assertStringContainsString('не найдено', $output, 'Output should indicate coating was not found');
    }

    public function test_missing_coating_title_option_fails(): void
    {
        $exitCode = $this->tester->execute([
            'docx' => self::FIXTURE_DOCX,
        ]);

        self::assertSame(1, $exitCode, 'Command should exit with failure when --coating-title is missing');

        $output = $this->tester->getDisplay();
        self::assertStringContainsString('coating-title обязательна', $output, 'Output should indicate --coating-title is required');
    }
}
