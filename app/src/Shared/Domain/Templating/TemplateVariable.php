<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

/**
 * Дескриптор плейсхолдера из интроспекции шаблона: имя + признак опциональности.
 * optional = помечен в шаблоне как {{name?}} либо сидит внутри опционального блока.
 */
final readonly class TemplateVariable
{
    public function __construct(
        public string $name,
        public bool $optional,
    ) {
    }
}
