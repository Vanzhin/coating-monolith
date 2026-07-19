<?php
/**
 * One-off tool: guarantee that every substance in each seed JSON has at least
 * one Russian alias.
 *
 * Rule per user request: если canonical не содержит кириллицы — обязательно
 * должен быть хотя бы один русский alias.
 *
 * Strategy:
 *   1. If canonical already contains Cyrillic → nothing to do.
 *   2. If any existing alias contains Cyrillic → nothing to do.
 *   3. Try to extract a Russian synonym from the PubChem cache (tools/pubchem-cache.json).
 *   4. Otherwise, generate a synthetic Russian alias via chemistry-aware
 *      transliteration (see russify() below).
 *
 * Usage:
 *   docker cp add-russian-aliases.php coating-monolith-manager_php-fpm-1:/tmp/rusify.php
 *   docker cp pubchem-cache.json     coating-monolith-manager_php-fpm-1:/tmp/pubchem-cache.json
 *   docker exec coating-monolith-manager_php-fpm-1 \
 *     php /tmp/rusify.php /app/src/ChemicalResistance/Infrastructure/Database/Seed
 */

declare(strict_types=1);

$seedDir = rtrim($argv[1] ?? '/app/src/ChemicalResistance/Infrastructure/Database/Seed', '/');
$cachePath = is_readable(__DIR__ . '/pubchem-cache.json')
    ? __DIR__ . '/pubchem-cache.json'
    : '/tmp/pubchem-cache.json';

$cache = is_readable($cachePath)
    ? json_decode(file_get_contents($cachePath), true, flags: JSON_THROW_ON_ERROR)
    : [];

/**
 * Reverse-lookup PubChem synonyms → russian, indexed by any raw name we
 * probed (so we can find a Russian synonym for a docx canonical by name).
 */
$pubchemRussianByAnyName = [];
foreach ($cache as $probedName => $entry) {
    $syns = $entry['synonyms'] ?? [];
    $russian = null;
    foreach ($syns as $s) {
        if (preg_match('/[а-яА-ЯёЁ]/u', $s)) {
            $russian = $s;
            break;
        }
    }
    if ($russian !== null) {
        $pubchemRussianByAnyName[strtolower(trim($probedName))] = $russian;
    }
}

$files = glob($seedDir . '/litatank_*.json') ?: [];
foreach ($files as $path) {
    $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    $added = 0;
    $viaPubchem = 0;
    $viaTranslit = 0;

    foreach ($data['substances'] as $idx => $sub) {
        if (hasCyrillic($sub['canonical'])) { continue; }
        foreach ($sub['aliases'] as $a) {
            if (hasCyrillic($a)) { continue 2; }
        }

        $found = $pubchemRussianByAnyName[strtolower($sub['canonical'])] ?? null;
        if ($found === null) {
            foreach ($sub['aliases'] as $a) {
                $found = $pubchemRussianByAnyName[strtolower($a)] ?? null;
                if ($found !== null) { break; }
            }
        }

        if ($found !== null) {
            $viaPubchem++;
        } else {
            $found = russify($sub['canonical']);
            $viaTranslit++;
        }

        if ($found !== '') {
            $data['substances'][$idx]['aliases'][] = $found;
            $added++;
        }
    }

    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    fprintf(
        STDERR,
        "%s: +%d russian aliases (%d via PubChem, %d via translit)\n",
        basename($path), $added, $viaPubchem, $viaTranslit
    );
}

function hasCyrillic(string $s): bool
{
    return (bool) preg_match('/[а-яА-ЯёЁ]/u', $s);
}

/**
 * Chemistry-aware Russification. Preserves numeric prefixes, digits, quotes,
 * commas, parentheses. Applies:
 *   1. Named cations / anions / functional-group English → Russian pairs
 *      (Sodium → Натрий, ...ide → ...ид, ...ate → ...ат, ...ic acid → ...ая кислота, ...).
 *   2. Word-order swap for salts (X hydroxide → Гидроксид X'а).
 *   3. Fallback: character-by-character transliteration.
 */
function russify(string $en): string
{
    $s = trim($en);
    if ($s === '') return '';

    // Well-known cations at the START of the name — "Sodium hydroxide" → salt form
    $cations = [
        'sodium'    => 'натрий',
        'potassium' => 'калий',
        'calcium'   => 'кальций',
        'magnesium' => 'магний',
        'ammonium'  => 'аммоний',
        'zinc'      => 'цинк',
        'iron'      => 'железо',
        'ferric'    => 'железо(III)',
        'ferrous'   => 'железо(II)',
        'copper'    => 'медь',
        'cupric'    => 'медь(II)',
        'aluminum'  => 'алюминий',
        'aluminium' => 'алюминий',
        'lithium'   => 'литий',
        'strontium' => 'стронций',
        'barium'    => 'барий',
        'manganese' => 'марганец',
        'chromium'  => 'хром',
        'nickel'    => 'никель',
        'lead'      => 'свинец',
        'silver'    => 'серебро',
        'mercury'   => 'ртуть',
        'tin'       => 'олово',
        'cobalt'    => 'кобальт',
    ];
    $anions = [
        'hydroxide'    => 'гидроксид',
        'oxide'        => 'оксид',
        'peroxide'     => 'пероксид',
        'chloride'     => 'хлорид',
        'fluoride'     => 'фторид',
        'bromide'      => 'бромид',
        'iodide'       => 'иодид',
        'sulfate'      => 'сульфат',
        'sulphate'     => 'сульфат',
        'sulfite'      => 'сульфит',
        'sulfide'      => 'сульфид',
        'sulphide'     => 'сульфид',
        'thiosulfate'  => 'тиосульфат',
        'nitrate'      => 'нитрат',
        'nitrite'      => 'нитрит',
        'phosphate'    => 'фосфат',
        'phosphite'    => 'фосфит',
        'carbonate'    => 'карбонат',
        'bicarbonate'  => 'гидрокарбонат',
        'acetate'      => 'ацетат',
        'benzoate'     => 'бензоат',
        'formate'      => 'формиат',
        'lactate'      => 'лактат',
        'oxalate'      => 'оксалат',
        'citrate'      => 'цитрат',
        'stearate'     => 'стеарат',
        'palmitate'    => 'пальмитат',
        'chlorate'     => 'хлорат',
        'perchlorate'  => 'перхлорат',
        'permanganate' => 'перманганат',
        'chromate'     => 'хромат',
        'dichromate'   => 'дихромат',
        'silicate'     => 'силикат',
        'borate'       => 'борат',
        'cyanide'      => 'цианид',
        'hypochlorite' => 'гипохлорит',
        'metaphosphate'=> 'метафосфат',
    ];

    // Try "X <anion>" pattern (case-insensitive), produce Russian salt name.
    foreach ($cations as $catEn => $catRu) {
        foreach ($anions as $anEn => $anRu) {
            $re = '/^' . preg_quote($catEn, '/') . '\s+' . preg_quote($anEn, '/') . '$/i';
            if (preg_match($re, $s)) {
                // Russian salt idiom: "Хлорид натрия" — anion capitalized, cation in genitive.
                return ucfirst($anRu) . ' ' . genitive($catRu);
            }
        }
    }
    // Also "X <cation>" not common in English; skipped.

    // Suffix / word replacements — applied on lowercased copy while remembering caps.
    $lowered = mb_strtolower($s);

    $replacements = [
        // Longer patterns first
        'hydrochloric acid'  => 'соляная кислота',
        'sulfuric acid'      => 'серная кислота',
        'sulphuric acid'     => 'серная кислота',
        'nitric acid'        => 'азотная кислота',
        'phosphoric acid'    => 'фосфорная кислота',
        'acetic acid'        => 'уксусная кислота',
        'formic acid'        => 'муравьиная кислота',
        'oxalic acid'        => 'щавелевая кислота',
        'lactic acid'        => 'молочная кислота',
        'citric acid'        => 'лимонная кислота',
        'malic acid'         => 'яблочная кислота',
        'tartaric acid'      => 'винная кислота',
        'benzoic acid'       => 'бензойная кислота',
        'boric acid'         => 'борная кислота',
        'carbolic acid'      => 'карболовая кислота',
        'palm oil'           => 'пальмовое масло',
        'soybean oil'        => 'соевое масло',
        'sunflower oil'      => 'подсолнечное масло',
        'castor oil'         => 'касторовое масло',
        'olive oil'          => 'оливковое масло',
        'linseed oil'        => 'льняное масло',
        'coconut oil'        => 'кокосовое масло',
        'corn oil'           => 'кукурузное масло',
        'rapeseed oil'       => 'рапсовое масло',
        'peanut oil'         => 'арахисовое масло',
        'cottonseed oil'     => 'хлопковое масло',
        'crude oil'          => 'сырая нефть',
        'fuel oil'           => 'мазут',
        'gas oil'            => 'газойль',
        'mineral oil'        => 'минеральное масло',
        'vegetable oil'      => 'растительное масло',
        'lubricating oil'    => 'смазочное масло',
        'diesel oil'         => 'дизельное топливо',
        'diesel fuel'        => 'дизельное топливо',
        'gasoline'           => 'бензин',
        'petrol'             => 'бензин',
        'kerosene'           => 'керосин',
        'jet fuel'           => 'авиатопливо',
        'aviation gasoline'  => 'авиабензин',
        'crude petroleum'    => 'сырая нефть',
        'sea water'          => 'морская вода',
        'seawater'           => 'морская вода',
        'saltwater'          => 'солёная вода',
        'salt water'         => 'солёная вода',
        'ethyl alcohol'      => 'этиловый спирт',
        'methyl alcohol'     => 'метиловый спирт',
        'isopropyl alcohol'  => 'изопропиловый спирт',
        'benzyl alcohol'     => 'бензиловый спирт',
        'butyl alcohol'      => 'бутиловый спирт',
        'propyl alcohol'     => 'пропиловый спирт',
        'wood alcohol'       => 'древесный спирт',
        'ethylene glycol'    => 'этиленгликоль',
        'propylene glycol'   => 'пропиленгликоль',
        'polyethylene glycol' => 'полиэтиленгликоль',
        'polyvinyl chloride' => 'поливинилхлорид',
        'polyvinyl alcohol'  => 'поливиниловый спирт',
        'silicon dioxide'    => 'диоксид кремния',
    ];

    foreach ($replacements as $pat => $rep) {
        if (mb_strpos($lowered, $pat) !== false) {
            $s = str_ireplace($pat, $rep, $s);
            $lowered = mb_strtolower($s);
        }
    }

    // Suffix table: token-by-token (word suffix boundaries).
    $suffixes = [
        ' hydroxide'  => ' гидроксид',
        ' oxide'      => ' оксид',
        ' peroxide'   => ' пероксид',
        ' chloride'   => ' хлорид',
        ' fluoride'   => ' фторид',
        ' bromide'    => ' бромид',
        ' iodide'     => ' иодид',
        ' sulfate'    => ' сульфат',
        ' sulphate'   => ' сульфат',
        ' sulfide'    => ' сульфид',
        ' sulphide'   => ' сульфид',
        ' nitrate'    => ' нитрат',
        ' phosphate'  => ' фосфат',
        ' carbonate'  => ' карбонат',
        ' acetate'    => ' ацетат',
        ' methacrylate' => ' метакрилат',
        ' acrylate'   => ' акрилат',
        ' benzene'    => ' бензен',
        ' phenol'     => ' фенол',
        ' alcohol'    => ' спирт',
        ' ether'      => ' эфир',
        ' ester'      => ' эфир',
        ' amine'      => ' амин',
        ' amide'      => ' амид',
        ' aldehyde'   => ' альдегид',
        ' ketone'     => ' кетон',
        ' acid'       => ' кислота',
    ];
    foreach ($suffixes as $en => $ru) {
        if (str_ends_with($lowered, $en)) {
            $s = mb_substr($s, 0, mb_strlen($s) - mb_strlen($en)) . $ru;
            $lowered = mb_strtolower($s);
            break;
        }
    }

    // Final: pass through character transliteration for what's still Latin.
    $s = translitLatin($s);

    // Uppercase first letter.
    $s = ucfirstMb($s);

    return $s;
}

/** Sodium → натрия etc. (very-rough — good enough for search-alias purposes). */
function genitive(string $ru): string
{
    static $map = [
        'натрий'    => 'натрия',
        'калий'     => 'калия',
        'кальций'   => 'кальция',
        'магний'    => 'магния',
        'аммоний'   => 'аммония',
        'цинк'      => 'цинка',
        'железо'    => 'железа',
        'железо(iii)' => 'железа(III)',
        'железо(ii)'  => 'железа(II)',
        'медь'      => 'меди',
        'медь(ii)'  => 'меди(II)',
        'алюминий'  => 'алюминия',
        'литий'     => 'лития',
        'стронций'  => 'стронция',
        'барий'     => 'бария',
        'марганец'  => 'марганца',
        'хром'      => 'хрома',
        'никель'    => 'никеля',
        'свинец'    => 'свинца',
        'серебро'   => 'серебра',
        'ртуть'     => 'ртути',
        'олово'     => 'олова',
        'кобальт'   => 'кобальта',
    ];
    $k = mb_strtolower($ru);
    return $map[$k] ?? $ru;
}

function translitLatin(string $s): string
{
    // A pre-pass to catch generic words we couldn't handle via the salt/suffix
    // tables above but still appear frequently mid-string.
    $wordReplace = [
        'acid'      => 'кислота',
        'acids'     => 'кислоты',
        'ester'     => 'эфир',
        'esters'    => 'эфиры',
        'ether'     => 'эфир',
        'ethers'    => 'эфиры',
        'oil'       => 'масло',
        'oils'      => 'масла',
        'fat'       => 'жир',
        'fats'      => 'жиры',
        'wax'       => 'воск',
        'waxes'     => 'воски',
        'solution'  => 'раствор',
        'aqueous'   => 'водный',
        'anhydrous' => 'безводный',
        'aqua'      => 'вода',
        'alcohol'   => 'спирт',
        'alcohols'  => 'спирты',
        'benzene'   => 'бензол',
        'benzol'    => 'бензол',
        'toluene'   => 'толуол',
        'xylene'    => 'ксилол',
        'xylol'     => 'ксилол',
        'aircraft'  => 'авиационный',
        'sludge'    => 'осадок',
        'anhydride' => 'ангидрид',
        'anhydrides'=> 'ангидриды',
    ];
    foreach ($wordReplace as $en => $ru) {
        $s = preg_replace('/\b' . preg_quote($en, '/') . '\b/i', $ru, $s);
    }

    // Multichar digraphs — apply before single-letter map.
    $s = strtr($s, [
        'ch'=>'ч','sh'=>'ш','ph'=>'ф','th'=>'т',
        'Ch'=>'Ч','Sh'=>'Ш','Ph'=>'Ф','Th'=>'Т',
        'yu'=>'ю','ya'=>'я','yo'=>'ё','ye'=>'е',
        'Yu'=>'Ю','Ya'=>'Я','Yo'=>'Ё','Ye'=>'Е',
    ]);

    // Context-sensitive 'c':  ce/ci/cy → с, otherwise → к.
    $s = preg_replace_callback('/[Cc][aeiouyAEIOUY]?/u', static function (array $m): string {
        $ch = $m[0];
        $isUpper = ctype_upper($ch[0]);
        if (mb_strlen($ch) === 1) {
            // Standalone 'c' at end of word or before consonant → к
            return $isUpper ? 'К' : 'к';
        }
        $next = mb_strtolower(mb_substr($ch, 1, 1));
        // "ce / ci / cy" — soft (с), otherwise hard (к).
        $r = in_array($next, ['e', 'i', 'y'], true) ? 'с' : 'к';
        $head = $isUpper ? mb_strtoupper($r) : $r;
        // Preserve the vowel that followed c (transliterate it via single-letter map later).
        return $head . mb_substr($ch, 1, 1);
    }, $s);

    $map = [
        'a'=>'а','b'=>'б','d'=>'д','e'=>'е','f'=>'ф','g'=>'г',
        'h'=>'х','i'=>'и','j'=>'ж','k'=>'к','l'=>'л','m'=>'м','n'=>'н',
        'o'=>'о','p'=>'п','q'=>'к','r'=>'р','s'=>'с','t'=>'т','u'=>'у',
        'v'=>'в','w'=>'в','x'=>'кс','y'=>'и','z'=>'з',
        'A'=>'А','B'=>'Б','D'=>'Д','E'=>'Е','F'=>'Ф','G'=>'Г',
        'H'=>'Х','I'=>'И','J'=>'Ж','K'=>'К','L'=>'Л','M'=>'М','N'=>'Н',
        'O'=>'О','P'=>'П','Q'=>'К','R'=>'Р','S'=>'С','T'=>'Т','U'=>'У',
        'V'=>'В','W'=>'В','X'=>'Кс','Y'=>'И','Z'=>'З',
        // Common non-ASCII we don't want left as-is.
        'ä'=>'а','ö'=>'о','ü'=>'у','ß'=>'сс',
        'Ä'=>'А','Ö'=>'О','Ü'=>'У',
    ];
    return strtr($s, $map);
}

function ucfirstMb(string $s): string
{
    if ($s === '') return '';
    $first = mb_strtoupper(mb_substr($s, 0, 1));
    return $first . mb_substr($s, 1);
}
