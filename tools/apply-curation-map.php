<?php
/**
 * One-off tool: apply curation-map (Russian canonical + CAS + aliases) to all
 * seed JSON files in-place.
 *
 * For each curated entry:
 *   - Match any docx substance whose normalized canonical or normalized alias
 *     equals the normalized aliases (or canonical) of the curated entry.
 *   - If a match is found in a seed file:
 *       - Replace the substance's canonical with the curated Russian one.
 *       - Set its cas to the curated cas (if not null).
 *       - Merge aliases: existing raw name kept, plus every curated alias not
 *         already present (by normalized comparison).
 *   - After substances are rewritten, update assessments[].substance refs to
 *     point at the new canonical string.
 *
 * Usage:
 *   docker exec coating-monolith-manager_php-fpm-1 \
 *     php /tmp/apply.php /app/src/ChemicalResistance/Infrastructure/Database/Seed
 *
 * Idempotent: running twice produces the same output.
 */

declare(strict_types=1);

require '/app/vendor/autoload.php';

use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php apply-curation-map.php <seed-dir>\n");
    exit(1);
}

$seedDir = rtrim($argv[1], '/');
$mapPath = __DIR__ . '/curation-map.json';   // must sit next to this script inside container

if (!is_readable($mapPath)) {
    // If script was copied into /tmp, the map should be at /tmp/curation-map.json.
    $mapPath = '/tmp/curation-map.json';
    if (!is_readable($mapPath)) {
        fwrite(STDERR, "curation-map.json not found next to script\n");
        exit(1);
    }
}

$map = json_decode(file_get_contents($mapPath), true, flags: JSON_THROW_ON_ERROR);

// Build lookup: normalized-alias-or-canonical → curated entry index
$lookupByNormalizedName = [];
foreach ($map as $i => $entry) {
    $keys = [$entry['canonical'], ...($entry['aliases'] ?? [])];
    foreach ($keys as $k) {
        $normalized = SubstanceNameNormalizer::normalize($k);
        if ($normalized === '') { continue; }
        if (!isset($lookupByNormalizedName[$normalized])) {
            $lookupByNormalizedName[$normalized] = $i;
        }
    }
}

$files = glob($seedDir . '/litatank_*.json');
if (!$files) {
    fwrite(STDERR, "No seed files found in $seedDir\n");
    exit(1);
}

foreach ($files as $path) {
    $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $renameMap = [];        // old canonical → new canonical
    $curatedApplied = 0;

    foreach ($data['substances'] as $idx => $sub) {
        $canonicalNorm = SubstanceNameNormalizer::normalize($sub['canonical']);
        if ($canonicalNorm === '') { continue; }

        $curatedIdx = $lookupByNormalizedName[$canonicalNorm] ?? null;
        if ($curatedIdx === null) {
            // Also try each existing alias in case docx aliased it earlier
            foreach ($sub['aliases'] as $a) {
                $aNorm = SubstanceNameNormalizer::normalize($a);
                if (isset($lookupByNormalizedName[$aNorm])) {
                    $curatedIdx = $lookupByNormalizedName[$aNorm];
                    break;
                }
            }
        }
        if ($curatedIdx === null) { continue; }

        $curated = $map[$curatedIdx];
        $oldCanonical = $sub['canonical'];
        $newCanonical = $curated['canonical'];

        // If two different docx substances map to the SAME curated Russian
        // canonical (e.g. both «Ethylene glycol» and «Ethane-1,2-diol» rows in
        // the same docx pointing at «Этиленгликоль»), the second one must be
        // merged. Look for an existing substance already renamed to this
        // canonical.
        $existingTargetIdx = null;
        foreach ($data['substances'] as $j => $s) {
            if ($j === $idx) { continue; }
            if ($s['canonical'] === $newCanonical) {
                $existingTargetIdx = $j;
                break;
            }
        }

        if ($existingTargetIdx !== null) {
            // Merge: fold this substance's original name + existing aliases into the target.
            $target = &$data['substances'][$existingTargetIdx];
            $mergedAliases = $target['aliases'];
            $addIfNew = function (array &$aliases, string $newAlias) {
                $newNorm = SubstanceNameNormalizer::normalize($newAlias);
                foreach ($aliases as $a) {
                    if (SubstanceNameNormalizer::normalize($a) === $newNorm) { return; }
                }
                $aliases[] = $newAlias;
            };
            $addIfNew($mergedAliases, $oldCanonical);
            foreach ($sub['aliases'] as $a) { $addIfNew($mergedAliases, $a); }
            $target['aliases'] = array_values($mergedAliases);
            unset($target);

            // Mark rename so assessments switch to the surviving canonical.
            $renameMap[$oldCanonical] = $newCanonical;
            // Delete this duplicate substance entry.
            unset($data['substances'][$idx]);
            $curatedApplied++;
            continue;
        }

        // Standard case: rewrite in place.
        $mergedAliases = $sub['aliases'];
        // Preserve original docx canonical as an alias so search still finds it.
        $addIfNew = function (array &$aliases, string $newAlias, string $canonicalNorm) {
            $newNorm = SubstanceNameNormalizer::normalize($newAlias);
            if ($newNorm === $canonicalNorm) { return; }   // don't self-alias
            foreach ($aliases as $a) {
                if (SubstanceNameNormalizer::normalize($a) === $newNorm) { return; }
            }
            $aliases[] = $newAlias;
        };
        $newCanonicalNorm = SubstanceNameNormalizer::normalize($newCanonical);
        $addIfNew($mergedAliases, $oldCanonical, $newCanonicalNorm);
        foreach ($curated['aliases'] ?? [] as $a) {
            $addIfNew($mergedAliases, $a, $newCanonicalNorm);
        }

        $data['substances'][$idx] = [
            'canonical' => $newCanonical,
            'cas'       => $curated['cas'] ?? $sub['cas'],
            'aliases'   => array_values($mergedAliases),
        ];
        if ($oldCanonical !== $newCanonical) {
            $renameMap[$oldCanonical] = $newCanonical;
        }
        $curatedApplied++;
    }

    // Re-index substances (unset may have created gaps)
    $data['substances'] = array_values($data['substances']);

    // Update assessments substance refs
    foreach ($data['assessments'] as &$a) {
        if (isset($renameMap[$a['substance']])) {
            $a['substance'] = $renameMap[$a['substance']];
        }
    }
    unset($a);

    // Sanity: every assessment.substance must exist as a substance canonical
    $canonicalSet = array_flip(array_column($data['substances'], 'canonical'));
    $orphans = 0;
    foreach ($data['assessments'] as $a) {
        if (!isset($canonicalSet[$a['substance']])) { $orphans++; }
    }

    // Count how many substances have Russian canonical (cyrillic in first char) and CAS
    $withRussian = 0; $withCas = 0;
    foreach ($data['substances'] as $s) {
        if (preg_match('/[а-яА-ЯёЁ]/u', $s['canonical'])) { $withRussian++; }
        if ($s['cas'] !== null) { $withCas++; }
    }

    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    fprintf(
        STDERR,
        "%s: %d substances, %d curated applied, %d with Russian canonical, %d with CAS, %d orphaned assessment refs\n",
        basename($path),
        count($data['substances']),
        $curatedApplied,
        $withRussian,
        $withCas,
        $orphans,
    );
}
