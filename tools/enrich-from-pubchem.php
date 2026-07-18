<?php
/**
 * One-off tool: enrich seed JSONs with CAS numbers + synonyms fetched from
 * PubChem REST API.
 *
 * For every substance in each seed file that lacks a CAS, we:
 *   1. Look up compound CID by the canonical name (and by each alias if the
 *      canonical doesn't resolve).
 *   2. Fetch synonyms list for that CID.
 *   3. Extract the first CAS-shaped token (NNNNNNN-NN-N) from the synonyms.
 *   4. Optionally add up to 5 non-empty synonyms as new aliases (skipping
 *      normalized-duplicates).
 *
 * Rate limit: 5 req/sec (200 ms sleep between requests) — the polite PubChem
 * budget. Progress is printed to stderr.
 *
 * Local cache in `tools/pubchem-cache.json` — the map (raw name → result) is
 * saved after every 10 lookups so an interrupted run resumes fast.
 *
 * Usage:
 *   php tools/enrich-from-pubchem.php <seed-dir> [--limit=N] [--fresh]
 *
 * Container invocation (if PubChem is not reachable from host):
 *   docker cp tools/enrich-from-pubchem.php coating-monolith-manager_php-fpm-1:/tmp/enrich.php
 *   docker exec coating-monolith-manager_php-fpm-1 php /tmp/enrich.php /app/src/ChemicalResistance/Infrastructure/Database/Seed
 */

declare(strict_types=1);

// Optional: use the app's normalizer if available.
$vendorAutoload = '/app/vendor/autoload.php';
if (is_readable($vendorAutoload)) {
    require $vendorAutoload;
}

$seedDir = $argv[1] ?? '/app/src/ChemicalResistance/Infrastructure/Database/Seed';
$seedDir = rtrim($seedDir, '/');
$limit = 0;
$fresh = false;
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    } elseif ($arg === '--fresh') {
        $fresh = true;
    }
}

$cachePath = __DIR__ . '/pubchem-cache.json';
if (!is_writable(dirname($cachePath))) {
    $cachePath = '/tmp/pubchem-cache.json';
}
$cache = (!$fresh && is_readable($cachePath))
    ? json_decode(file_get_contents($cachePath), true, flags: JSON_THROW_ON_ERROR)
    : [];

$files = glob($seedDir . '/litatank_*.json');
if (!$files) {
    fwrite(STDERR, "No seed files in $seedDir\n");
    exit(1);
}

/**
 * Collect the (unique) name-work-list across all seeds: canonical when it has
 * no CAS, else nothing. Also try each existing alias for names we haven't
 * resolved yet from cache.
 */
$workset = [];   // canonical → [alias, alias, ...] fallback probes
foreach ($files as $path) {
    $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    foreach ($data['substances'] as $sub) {
        if ($sub['cas'] !== null) {
            continue;
        }
        $canonical = $sub['canonical'];
        if (!isset($workset[$canonical])) {
            $workset[$canonical] = [];
        }
        foreach ($sub['aliases'] as $a) {
            if (!in_array($a, $workset[$canonical], true)) {
                $workset[$canonical][] = $a;
            }
        }
    }
}

fwrite(STDERR, sprintf("Unique substances lacking CAS: %d\n", count($workset)));

$done = 0;
$hits = 0;
$saveEvery = 10;

foreach ($workset as $canonical => $aliasProbes) {
    if ($limit > 0 && $done >= $limit) {
        break;
    }
    $done++;

    if (isset($cache[$canonical])) {
        // Already resolved (either with cas or null-marked as not-found).
        if (!empty($cache[$canonical]['cas'])) $hits++;
        continue;
    }

    // Try canonical first, then each alias.
    $probes = array_merge([$canonical], $aliasProbes);
    $result = null;
    foreach ($probes as $probe) {
        $probe = trim($probe);
        // Skip probes that are unlikely to resolve: contain %, path, empty, look like refs.
        if ($probe === '' || strlen($probe) > 200) continue;
        // Some names are marketing garbage; strip trailing (...) tail and asterisks.
        $probeClean = preg_replace('/\s*\(.*\)\s*$/u', '', $probe);
        $probeClean = trim(preg_replace('/\*.*$/u', '', $probeClean));
        if ($probeClean === '') continue;

        $result = lookupPubChem($probeClean);
        if ($result !== null) {
            $result['matched_via'] = $probeClean === $canonical ? 'canonical' : "alias:$probeClean";
            break;
        }
    }

    $cache[$canonical] = $result ?? ['cas' => null, 'synonyms' => [], 'iupac' => null, 'matched_via' => 'not_found'];
    if ($result !== null && !empty($result['cas'])) {
        $hits++;
    }

    if ($done % 5 === 0) {
        fwrite(STDERR, sprintf("  [%d/%d]  hits=%d  cached=%d  last=«%s» → %s\n",
            $done, count($workset), $hits, count($cache), $canonical,
            $result['cas'] ?? 'none',
        ));
    }
    if ($done % $saveEvery === 0) {
        file_put_contents($cachePath, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}

file_put_contents($cachePath, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
fwrite(STDERR, sprintf("Lookup done: %d processed, %d hits, cache at %s\n", $done, $hits, $cachePath));

/* ------------------------------------------------------------------ */
/* Apply cache to seed files                                            */
/* ------------------------------------------------------------------ */

foreach ($files as $path) {
    $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $applied = 0;
    $aliasesAdded = 0;

    foreach ($data['substances'] as &$sub) {
        if ($sub['cas'] !== null) continue;
        $canonical = $sub['canonical'];
        $result = $cache[$canonical] ?? null;
        if ($result === null) continue;

        if (!empty($result['cas'])) {
            // Sanity: make sure CAS is unique in this seed (avoid two different substances
            // clashing on same CAS after enrichment).
            $collision = false;
            foreach ($data['substances'] as $other) {
                if ($other === $sub) continue;
                if (isset($other['cas']) && $other['cas'] === $result['cas']) {
                    $collision = true;
                    break;
                }
            }
            if (!$collision) {
                $sub['cas'] = $result['cas'];
                $applied++;
            }
        }

        // Add up to 5 PubChem synonyms as aliases if genuinely new.
        if (!empty($result['synonyms'])) {
            $existing = array_map(fn($a) => normalizeName($a), $sub['aliases']);
            $existing[] = normalizeName($canonical);
            $added = 0;
            foreach ($result['synonyms'] as $syn) {
                if ($added >= 5) break;
                if (mb_strlen($syn) > 200) continue;
                $key = normalizeName($syn);
                if (in_array($key, $existing, true)) continue;
                // Skip CAS-only "aliases" — they're not really synonyms.
                if (preg_match('/^\d+-\d+-\d$/', $syn)) continue;
                $sub['aliases'][] = $syn;
                $existing[] = $key;
                $added++;
                $aliasesAdded++;
            }
        }
    }
    unset($sub);

    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    fwrite(STDERR, sprintf("%s: +%d CAS, +%d aliases\n", basename($path), $applied, $aliasesAdded));
}

/* ------------------------------------------------------------------ */
/* Helpers                                                              */
/* ------------------------------------------------------------------ */

/**
 * Returns ['cas' => string|null, 'synonyms' => list<string>, 'iupac' => ?string]
 * or null if PubChem knows nothing about this name.
 */
function lookupPubChem(string $name): ?array
{
    static $lastCall = 0.0;
    $now = microtime(true);
    $gap = $now - $lastCall;
    if ($gap < 0.2) {
        usleep((int) ((0.2 - $gap) * 1_000_000));
    }
    $lastCall = microtime(true);

    $urlName = rawurlencode($name);
    $cidsUrl = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/name/$urlName/cids/JSON";
    $body = httpGet($cidsUrl);
    if ($body === null) return null;
    $decoded = @json_decode($body, true);
    if (!isset($decoded['IdentifierList']['CID'][0])) return null;

    $cid = (int) $decoded['IdentifierList']['CID'][0];

    // Synonyms — grab up to a few dozen (PubChem often returns hundreds).
    $synUrl = "https://pubchem.ncbi.nlm.nih.gov/rest/pug/compound/cid/$cid/synonyms/JSON";
    $body = httpGet($synUrl);
    $synonyms = [];
    $cas = null;
    if ($body !== null) {
        $decoded = @json_decode($body, true);
        $synonyms = $decoded['InformationList']['Information'][0]['Synonym'] ?? [];
        foreach ($synonyms as $s) {
            if (preg_match('/^\d{2,7}-\d{2}-\d$/', $s)) {
                $cas = $s;
                break;
            }
        }
    }

    return [
        'cas' => $cas,
        'synonyms' => array_values(array_slice($synonyms, 0, 30)),
        'iupac' => null,
    ];
}

function httpGet(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'coating-monolith-seed-enricher/1.0 (contact: internal)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code !== 200) return null;
    return $body;
}

function normalizeName(string $s): string
{
    // Local mirror of SubstanceNameNormalizer::normalize — kept here so the
    // script also runs without app autoloading.
    if (class_exists(App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer::class)) {
        return App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer::normalize($s);
    }
    $s = \Normalizer::normalize($s, \Normalizer::FORM_KC) ?: $s;
    $s = mb_strtolower($s, 'UTF-8');
    $s = preg_replace('/\([ng]\)/u', '', $s) ?? $s;
    $s = preg_replace('/\*[^\s,()*]*\*?/u', '', $s) ?? $s;
    return trim(preg_replace('/[\s\-.,;\/\\\\]+/u', '', $s) ?? $s);
}
