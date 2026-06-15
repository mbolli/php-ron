<?php

declare(strict_types=1);

/*
 * Generates syntax-highlighted HTML for the static marketing page (docs/index.html).
 *
 * Uses tempest/highlight with the RON grammar from the sibling mbolli/tempest-highlight-ron
 * package. Run after changing any code snippet on the page:
 *
 *     php bin/highlight-docs.php            # prints each block + the CSS classes used
 *     php bin/highlight-docs.php --write    # writes docs/_partials/*.html
 *
 * The page is static (no build step at serve time); this is a one-off content generator.
 */

$autoload = __DIR__ . '/../../tempest-highlight-ron/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "tempest-highlight-ron not found at {$autoload}\n");

    exit(1);
}

require $autoload;

use Mbolli\TempestHighlightRon\RonLanguage;
use Tempest\Highlight\Highlighter;

$highlighter = (new Highlighter())->addLanguage(new RonLanguage());

$jsonPretty = <<<'JSON'
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

$ronPretty = <<<'RON'
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

$php = <<<'PHP'
    use Mbolli\Ron\Ron;

    // Encode/decode arbitrary PHP values, like json_encode/json_decode
    Ron::encode(['name' => 'Ada', 'active' => true]); // active true\nname Ada\n
    Ron::decode("name Ada\nactive true");             // ['active' => true, 'name' => 'Ada']

    // RON -> JSON (compact by default, canonical key order)
    Ron::toJson("name Ada\nactive true");          // {"active":true,"name":"Ada"}
    Ron::toJson($ron, pretty: true);                // multiline JSON

    // JSON -> RON (pretty by default, canonical key order)
    Ron::fromJson('{"name":"Ada","active":true}'); // active true\nname Ada\n
    Ron::fromJson($json, pretty: false);            // compact RON

    // Canonical RON and its unseeded XXH3-128 hash, and RFC 8785 (JCS) JSON
    Ron::canonicalRon($json);
    Ron::canonicalHash($json);
    Ron::canonicalJson($json);
    PHP;

$blocks = [
    'json' => $highlighter->parse($jsonPretty, 'json'),
    'ron' => $highlighter->parse($ronPretty, 'ron'),
    'php' => $highlighter->parse($php, 'php'),
];

if (in_array('--write', $argv, true)) {
    $dir = __DIR__ . '/../docs/_partials';
    if (!is_dir($dir)) {
        mkdir($dir, 0o755, true);
    }
    foreach ($blocks as $name => $html) {
        file_put_contents("{$dir}/{$name}.html", $html . "\n");
        echo "wrote docs/_partials/{$name}.html\n";
    }

    exit(0);
}

$classes = [];
foreach ($blocks as $name => $html) {
    echo "===== {$name} =====\n{$html}\n\n";
    preg_match_all('/class="([^"]+)"/', $html, $m);
    foreach ($m[1] as $c) {
        foreach (explode(' ', $c) as $cls) {
            $classes[$cls] = true;
        }
    }
}

ksort($classes);
echo "===== unique classes =====\n" . implode("\n", array_keys($classes)) . "\n";
