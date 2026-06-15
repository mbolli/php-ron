<?php

declare(strict_types=1);

/**
 * Regenerates the syntax-highlighted code in docs/index.html.
 *
 * Highlighting is produced by tempest/highlight with the RON language plugin
 * (mbolli/tempest-highlight-ron), which tokenizes through the real php-ron parser,
 * so RON keys, tags, and repeated-quote strings are coloured exactly. The plugin is
 * expected to live next to this repository:
 *
 *     /var/www/php-ron               (this repo)
 *     /var/www/tempest-highlight-ron (composer install run there)
 *
 * Each managed block is delimited by `<!-- build:NAME -->` / `<!-- /build:NAME -->`
 * markers in docs/index.html; content between them is replaced in place.
 *
 * Run: php bin/build-docs.php
 */

use Mbolli\TempestHighlightRon\RonLanguage;
use Tempest\Highlight\Highlighter;

$autoload = __DIR__ . '/../../tempest-highlight-ron/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "tempest-highlight-ron not found at {$autoload}\n");
    fwrite(STDERR, "Clone github.com/mbolli/tempest-highlight-ron next to php-ron and run `composer install` there.\n");

    exit(1);
}

require $autoload;

$highlighter = new Highlighter();
$highlighter->addLanguage(new RonLanguage());

$indexPath = __DIR__ . '/../docs/index.html';
$html = (string) file_get_contents($indexPath);

/**
 * Replaces the content between `<!-- build:NAME -->` markers; exits on a missing marker.
 */
$replace = static function (string $html, string $name, string $content): string {
    $start = "<!-- build:{$name} -->";
    $end = "<!-- /build:{$name} -->";
    $pattern = '/' . preg_quote($start, '/') . '.*?' . preg_quote($end, '/') . '/s';
    // preg_replace_callback (not preg_replace) so "$json" etc. are not read as backreferences.
    $result = preg_replace_callback($pattern, static fn (): string => $start . $content . $end, $html, 1, $count);
    if ($result === null || $count !== 1) {
        fwrite(STDERR, "build marker '{$name}' not found in docs/index.html\n");

        exit(1);
    }

    return $result;
};

// ── API reference table ────────────────────────────────────────────────────
// Each entry is [PHP signature, description HTML]. $maxDepth is omitted from the
// signatures; the table footnote documents it.
$methods = [
    ['toJson(string $ron, bool $pretty = false, bool $canonical = true)', 'RON to JSON (compact, canonical key order by default).'],
    ['fromJson(string $json, bool $pretty = true, bool $canonical = true, ?callable $mapper = null, array $vocabularies = [VocabularyRegistry::CORE_V1], ?VocabularyRegistry $registry = null)', 'JSON to RON (pretty by default); optional typed-value render hook and typed-vocabulary validation (core enabled by default).'],
    ['encode(mixed $value, bool $pretty = true, bool $canonical = true)', 'Encode any PHP value as RON, like <code>json_encode</code>.'],
    ['decode(string $ron, bool $associative = true)', 'Decode RON to a PHP value, like <code>json_decode</code>.'],
    ['canonicalRon(string $json)', 'Compact, canonically-ordered RON (the canonical byte form).'],
    ['canonicalHash(string $json)', 'SHA-256 of the canonical RON, 64 lowercase hex digits.'],
    ['canonicalJson(string $json)', 'RFC 8785 (JCS) canonical JSON.'],
    ['validate(string $json, array $vocabularies = [VocabularyRegistry::CORE_V1], ?VocabularyRegistry $registry = null)', 'Validate typed payloads against the enabled vocabularies; throws on invalid.'],
    ['tokenize(string $ron)', 'Lenient, role-aware token stream over RON source (powers this page\'s highlighting).'],
];
$rows = [];
foreach ($methods as [$signature, $description]) {
    $rows[] = '          <tr><td><code>' . $highlighter->parse($signature, 'php') . "</code></td><td>{$description}</td></tr>";
}
$html = $replace($html, 'api-methods', "\n" . implode("\n", $rows) . "\n          ");

// ── Inline code blocks (markers sit inside <pre>, so content stays tight) ────
$jsonSample = <<<'JSON'
    {
      "enabled": true,
      "fallback": null,
      "greeting": "hello world",
      "limits": {
        "cpu": "500m",
        "memory": "256Mi"
      },
      "name": "web-server",
      "replicas": 3,
      "tags": [
        "prod",
        "edge"
      ]
    }
    JSON;

$ronSample = <<<'RON'
    enabled true
    fallback null
    greeting 'hello world'
    limits {
      cpu 500m
      memory 256Mi
    }
    name web-server
    replicas 3
    tags [prod edge]
    RON;

$vocabSample = <<<'RON'
    account {#uid 4f6e2a91-0c3d-4b7a-9f21-1a2b3c4d5e6f}
    created {#utc 2026-06-13T00:00:00Z}
    ttl {#dur PT1H30M}
    host {#ip4 192.0.2.1}
    balance {#dec '1234.56'}
    score {#f3v [1.5 2.5 3.5]}
    location {#lla [-73.9857 40.7484 381]}
    accent {#clr [oklch 0.7 0.15 230]}
    parent {# 300}
    RON;

$customVocab = <<<'PHP'
    use Mbolli\Ron\Value\RonNumber;
    use Mbolli\Ron\Value\RonObject;
    use Mbolli\Ron\Vocabulary\VocabularyRegistry;

    $registry = VocabularyRegistry::official();

    // A validator returns true to accept, false to reject, or a value to transform.
    // #com.acme/waypoint carries an object payload {lat, lng, label} with range checks.
    $registry->register('https://acme.example/vocab/geo/v1', [
        '#com.acme/waypoint' => function (mixed $payload): bool {
            if (!$payload instanceof RonObject) {
                return false;
            }
            $field = [];
            foreach ($payload->members() as [$key, $value]) {
                $field[$key] = $value;
            }
            $lat = $field['lat'] ?? null;
            $lng = $field['lng'] ?? null;

            return $lat instanceof RonNumber && abs((float) $lat->text) <= 90
                && $lng instanceof RonNumber && abs((float) $lng->text) <= 180;
        },
    ]);

    $json = '{"office":{"#com.acme/waypoint":{"lat":47.3769,"lng":8.5417,"label":"HQ"}}}';
    Ron::fromJson($json, vocabularies: ['https://acme.example/vocab/geo/v1'], registry: $registry);
    // office {#com.acme/waypoint {label HQ lat 47.3769 lng 8.5417}}
    PHP;

// Shorter "recipe" fragments: just the tag => validator entry plus an input -> output
// comment. They build on the full registration shown in $customVocab above.
$customMoney = <<<'PHP'
    // money as [currency, amount] -- returns true to accept, false to reject
    '#com.acme/money' => fn (mixed $p): bool => is_array($p) && count($p) === 2
        && is_string($p[0]) && preg_match('/^[A-Z]{3}$/', $p[0]) === 1
        && is_string($p[1]) && preg_match('/^-?\d+(\.\d+)?$/', $p[1]) === 1,

    // {"price":{"#com.acme/money":["EUR","19.99"]}}  ->  price {#com.acme/money [EUR '19.99']}
    PHP;

$customSemver = <<<'PHP'
    // a SemVer 2.0.0 version string
    '#org.semver/version' => fn (mixed $p): bool => is_string($p)
        && preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/', $p) === 1,

    // {"ron":{"#org.semver/version":"1.4.0-beta.2"}}  ->  ron {#org.semver/version 1.4.0-beta.2}
    PHP;

$customMeasure = <<<'PHP'
    // a measurement as [value, unit]
    '#com.acme/measure' => fn (mixed $p): bool => is_array($p) && count($p) === 2
        && $p[0] instanceof RonNumber
        && in_array($p[1], ['mm', 'cm', 'm', 'km', 'g', 'kg', 's', 'ms'], true),

    // {"height":{"#com.acme/measure":[1.83,"m"]}}  ->  height {#com.acme/measure [1.83 m]}
    PHP;

// A transformer recipe: instead of a yes/no check, the validator returns a reshaped
// payload (here a MultilineList, which renders one element per line like the built-in #vox).
$customMatrix = <<<'PHP'
    // The three return modes: false rejects, true accepts as-is, any other value transforms.
    '#com.acme/matrix' => function (mixed $payload): mixed {
        if (!is_array($payload)) {
            return false;                                    // reject
        }
        if ($payload === []) {
            return true;                                     // accept the payload unchanged
        }

        return new \Mbolli\Ron\Value\MultilineList($payload); // transform: one row per line
    },

    // {"grid":{"#com.acme/matrix":[]}}  ->  grid {#com.acme/matrix []}
    // {"id":{"#com.acme/matrix":[[1,0,0],[0,1,0],[0,0,1]]}}  ->
    // id {#com.acme/matrix [
    //   [1 0 0]
    //   [0 1 0]
    //   [0 0 1]
    // ]}
    PHP;

$customFlag = <<<'PHP'
    // To transform a payload INTO a bool, wrap it in replace() -- a bare true/false
    // return would be read as accept/reject instead of as the new payload.
    '#com.acme/flag' => fn (mixed $p): mixed => is_string($p)
        ? VocabularyRegistry::replace(in_array($p, ['on', 'yes', 'true'], true))
        : false,

    // {"beta":{"#com.acme/flag":"on"}}   ->  beta {#com.acme/flag true}
    // {"beta":{"#com.acme/flag":"off"}}  ->  beta {#com.acme/flag false}
    PHP;

$blocks = [
    'why-json' => ['json', $jsonSample],
    'why-ron' => ['ron', $ronSample],
    'vocab-sample' => ['ron', $vocabSample],
    'custom-vocab' => ['php', $customVocab],
    'custom-money' => ['php', $customMoney],
    'custom-semver' => ['php', $customSemver],
    'custom-measure' => ['php', $customMeasure],
    'custom-flag' => ['php', $customFlag],
    'custom-matrix' => ['php', $customMatrix],
];
foreach ($blocks as $name => [$language, $source]) {
    $html = $replace($html, $name, rtrim($highlighter->parse($source, $language), "\n"));
}

file_put_contents($indexPath, $html);
printf("Updated docs/index.html (%d API methods, %d code blocks).\n", count($methods), count($blocks));
