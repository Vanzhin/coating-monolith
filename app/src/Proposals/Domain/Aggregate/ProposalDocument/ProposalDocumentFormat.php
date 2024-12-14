<?php

namespace App\Proposals\Domain\Aggregate\ProposalDocument;

use App\Shared\Domain\Trait\EnumToArray;

/**
 * Типа покрытия по назначению
 */
enum ProposalDocumentFormat: string
{
    use EnumToArray;

    case PDF = 'pdf';
    case DOCX = 'docx';
    case XLSX = 'xlsx';
}
