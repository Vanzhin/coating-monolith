<?php

declare(strict_types=1);

namespace App\Compliance\Application\Scheduler;

use App\Compliance\Application\Service\DueSoonScanner;
use App\Shared\Application\Command\CommandHandlerInterface;

/**
 * Обработчик ежедневного сигнала Scheduler: запускает проход сроков. Реализует CommandHandlerInterface —
 * регистрируется на command.bus (default_bus), куда Scheduler и диспатчит сгенерированное сообщение.
 */
final readonly class ScanDueSoonHandler implements CommandHandlerInterface
{
    public function __construct(private DueSoonScanner $scanner)
    {
    }

    public function __invoke(ScanDueSoon $message): void
    {
        $this->scanner->scan(new \DateTimeImmutable());
    }
}
