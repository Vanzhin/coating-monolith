<?php
/**
 * One-off tool: convert a docx (Литатанк chemical resistance list) into the JSON
 * seed file consumed by the seed migrations.
 *
 * Usage:
 *   docker exec coating-monolith-manager_php-fpm-1 \
 *     php /app/tools/dump-docx-to-seed-json.php <docx> <coating-title> <out.json>
 *
 * Output shape:
 * {
 *   "coating_title": "...",
 *   "notes": [{"placeholder_label":"Прим. 1","title":"...","description":"..."}, ...],
 *   "substances": [{"canonical":"raw name","cas":null,"aliases":[]}, ...],
 *   "assessments": [{"substance":"raw name","grade":"R","max_temperature":null,"notes":["Прим. 1"]}, ...]
 * }
 *
 * Substances are deduplicated by normalized canonical key.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/app/vendor/autoload.php';

use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use App\ChemicalResistance\Infrastructure\Docx\DocxAssessmentParser;
use App\ChemicalResistance\Infrastructure\Docx\GradeCellParser;

if ($argc < 4) {
    fwrite(STDERR, "Usage: php dump-docx-to-seed-json.php <docx> <coating-title> <out.json>\n");
    exit(1);
}

$docxPath   = $argv[1];
$coatingTitle = $argv[2];
$outPath    = $argv[3];

$parser  = new DocxAssessmentParser();
$grader  = new GradeCellParser();
$parsed  = $parser->parse($docxPath);

$notes = [];
foreach ($parsed->notes as $n) {
    $notes[] = [
        'placeholder_label' => $n->label,
        'title'             => $n->title,
        'description'       => $n->description,
    ];
}

$substancesByKey = [];   // key => canonical (first-seen)
$assessments     = [];
$warnings        = [];

foreach ($parsed->rows as $row) {
    try {
        $g = $grader->parse($row->gradeCell);
    } catch (\Throwable $e) {
        $warnings[] = sprintf('«%s» → «%s»: %s', $row->substanceName, $row->gradeCell, $e->getMessage());
        continue;
    }

    $rawName = trim($row->substanceName);
    if ($rawName === '') {
        $warnings[] = sprintf('Empty substance name at grade «%s»', $row->gradeCell);
        continue;
    }

    $key = SubstanceNameNormalizer::normalize($rawName);
    // First-seen canonical wins; subsequent identical-key rows collapse into the same substance.
    if (!isset($substancesByKey[$key])) {
        $substancesByKey[$key] = $rawName;
    }
    $canonical = $substancesByKey[$key];

    $assessments[] = [
        'substance'       => $canonical,
        'grade'           => $g->grade,
        'max_temperature' => $g->maxTemperatureCelsius,
        'notes'           => $g->noteLabels,
    ];
}

$substances = [];
foreach ($substancesByKey as $canonical) {
    $substances[] = [
        'canonical' => $canonical,
        'cas'       => null,
        'aliases'   => [],
    ];
}

$out = [
    'coating_title' => $coatingTitle,
    'notes'         => $notes,
    'substances'    => $substances,
    'assessments'   => $assessments,
];

file_put_contents(
    $outPath,
    json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
);

fprintf(
    STDERR,
    "Wrote %s: %d substances, %d assessments, %d notes, %d warnings\n",
    $outPath,
    count($substances),
    count($assessments),
    count($notes),
    count($warnings),
);
foreach ($warnings as $w) {
    fprintf(STDERR, "  ! %s\n", $w);
}
