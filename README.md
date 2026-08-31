# php-ron

[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![CI](https://github.com/mbolli/php-ron/actions/workflows/ci.yml/badge.svg)](https://github.com/mbolli/php-ron/actions/workflows/ci.yml)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Downloads](https://img.shields.io/packagist/dt/mbolli/php-ron)](https://packagist.org/packages/mbolli/php-ron)
[![Tests](https://img.shields.io/badge/tests-309%20passing-brightgreen)](tests/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](.phpstan.neon)
[![Code style](https://img.shields.io/badge/code%20style-php--cs--fixer-brightgreen)](.php-cs-fixer.php)

A performance-focused PHP implementation of [RON (Readable Object Notation)](https://github.com/starfederation/ron).

RON keeps JSON's value model but drops avoidable syntax: top-level object braces can be
elided, strings can be bare, commas are optional separators, and quoted strings use repeated
`'`/`"` delimiters. Every string form -- bare, quoted, repeated-delimiter, and object keys --
uses the JSON escape set. It converts losslessly to and from JSON and is cheaper for humans
and LLMs to read and write.

Reach for it wherever you'd use JSON but a person or an LLM authors or reads the data — config
files, fixtures, logs, and prompt/context payloads where the saved quotes and braces add up to
real tokens. Because every RON document maps 1:1 to a JSON value, you can adopt it only at those
edges (author or display RON, keep storing and transmitting JSON) without changing your data model.

This library is a port of the reference Go implementation
([ron-go](https://github.com/starfederation/ron-go)) and passes the upstream conformance
corpus (`testdata/conformance`) and the RFC 8785 corpus (`testdata/rfc8785`).

## Requirements

- PHP >= 8.3 (`ext-hash` for `sha256`, `ext-mbstring`)

## Install

```bash
composer require mbolli/php-ron
```

## Usage

```php
use Mbolli\Ron\Ron;
use Mbolli\Ron\RonMode;

// Encode/decode arbitrary PHP values, like json_encode/json_decode
Ron::encode(['name' => 'Ada', 'active' => true]); // "name Ada\nactive true"
Ron::encode($data, RonMode::Compact);             // compact RON
Ron::decode("name Ada\nactive true");             // ['name' => 'Ada', 'active' => true]

// RON -> JSON
Ron::toJson("name Ada\nactive true");                // multiline JSON (pretty is the default)
Ron::toJson($ron, RonMode::Compact);                 // {"name":"Ada","active":true}

// JSON -> RON
Ron::fromJson('{"name":"Ada","active":true}');       // "name Ada\nactive true"
Ron::fromJson($json, RonMode::Compact);              // compact RON

// Reformat RON in place (RON -> RON)
Ron::format($ron, RonMode::Compact);

// Canonical RON and its SHA-256 hash (64 lowercase hex)
Ron::canonicalRon($json);
Ron::canonicalHash($json);

// RFC 8785 (JCS) canonical JSON
Ron::canonicalJson($json);
```

Invalid input throws `Mbolli\Ron\RonException`.

### Output modes

Every conversion takes one `RonMode`, and `Pretty` is the default:

| Mode | Output | Member order |
| --- | --- | --- |
| `RonMode::Pretty` | multiline | source order |
| `RonMode::Compact` | single line | source order |
| `RonMode::Canonical` | single line | sorted, RFC 8785 UTF-16 code-unit order |

`Canonical` is not just sorted compact output. It applies the full RFC 8785 and RFC 7493
(I-JSON) contract to the value: duplicate decoded member names, invalid Unicode, Unicode
noncharacters and numbers whose IEEE 754 conversion is not finite are rejected, and numbers
are re-serialized with the ECMAScript algorithm, so input number spelling is not preserved.
`Pretty` and `Compact` do preserve number text.

Pretty RON ends with one trailing newline; compact and canonical RON do not.

### Typed-value render hooks

`fromJson` accepts an optional hook that rewrites JSON values before rendering, e.g. to emit
typed RON forms. The hook receives the path (object keys as strings, array indices as ints;
root is `[]`) and the value, and returns `[replacement, replaced]`:

```php
Ron::fromJson($json, mapper: function (array $path, mixed $value): array {
    if ($path === ['committed']) {
        return [['#utc' => $value], true]; // -> committed {#utc ...}
    }
    return [$value, false];
});
```

### Typed vocabularies

A typed value is a single-key object whose key starts with `#`, e.g. `{"#utc": "..."}`, which RON
renders compactly as `{#utc ...}`. This rendering is always on. Optionally, `fromJson` can validate
typed payloads against the [official vocabularies](https://github.com/starfederation/ron/blob/main/docs/vocabularies.md)
(core, time, network, set, math, spatial, color, geo). The **core** vocabulary is enabled by
default; the rest are opt-in. Pass `vocabularies: []` to disable validation entirely.

Most vocabularies only validate, so tagged objects round-trip unchanged. Three deliberately
transform their payload: `#vox` forces its `cells` list multiline, `#set` sorts and deduplicates
its elements by canonical JSON bytes, and `#bits` normalizes uint32 indexes into ascending,
merged inclusive ranges.

```php
use Mbolli\Ron\Vocabulary\VocabularyRegistry;

// Core is validated by default: a malformed payload throws RonException.
Ron::fromJson('{"id":{"#uid":"not-a-uuid"}}');                       // throws

// Enable additional vocabularies explicitly.
Ron::fromJson($json, vocabularies: [
    VocabularyRegistry::CORE_V1,
    VocabularyRegistry::TIME_V1,
]);

// Validate without rendering.
Ron::validate($json, [VocabularyRegistry::SPATIAL_V1]);

// Register a custom, namespaced vocabulary. A validator returns true to accept the
// payload, false to reject it, or any other value to replace it (a transform); to
// replace it with a literal boolean, return VocabularyRegistry::replace($bool).
$registry = VocabularyRegistry::official();
$registry->register('https://example.com/vocab/invoice/v1', [
    '#com.example/money' => static fn (mixed $payload): bool => is_array($payload) && count($payload) === 2,
]);
Ron::fromJson($json, vocabularies: ['https://example.com/vocab/invoice/v1'], registry: $registry);
```

Unknown typed values are left as ordinary objects; enabling a vocabulary the registry does not
support throws.

## Scope

This implementation matches ron-go: RON<->JSON conversion in all three modes, RFC 8785 canonical
JSON, canonical RON, the typed-value render hook, and typed-vocabulary validation (validation runs
on the JSON->RON path; `toJson` is not validated). Apart from the three transforming tags listed
above, validation preserves the value model, so vocabulary-tagged objects round-trip losslessly.

`#topo` (TopoJSON) and the geo/color vocabularies are written from the spec's
`docs/vocabularies.md` and JSON Schemas, since ron-go has not built them yet.

## Performance

The hot paths scan bytes with native C functions (`strcspn`/`strpos`) instead of per-character
PHP loops, sort object keys with `array_multisort` (no per-comparison PHP callback), and stream
RON->JSON directly without an intermediate tree. The canonical hash uses native `hash('sha256')`.

Measured throughput (OPcache + JIT), flat and linear from ~1.5 KB to ~320 KB, on the same input
against the Go reference ([ron-go](https://github.com/starfederation/ron-go), compiled):

| Conversion            | php-ron  | ron-go (Go) | vs ron-go    |
| --------------------- | -------- | ----------- | ------------ |
| JSON -> RON (compact) | ~29 MB/s | ~12 MB/s    | ~2.4x faster |
| JSON -> RON (pretty)  | ~26 MB/s | ~12 MB/s    | ~2.2x faster |
| RON -> JSON (compact) | ~19 MB/s | ~147 MB/s   | ~7.6x slower |
| canonical hash        | ~13 MB/s | ~5 MB/s     | ~2.5x faster |

php-ron is ahead on both JSON->RON directions and on the canonical hash: ron-go decodes JSON
through `encoding/json`'s token API into an ordered object, while php-ron uses a hand-rolled
`JsonParser`, and its canonical path validates and renders from a single parse rather than a
canonical-JSON round trip. The compiled reference is well ahead on RON->JSON, which streams
through a dedicated hot path with pooled buffers — the realistic interpreter tax for byte-level
streaming against optimized Go. A 1-10 KB payload converts in well under a millisecond either way.

RON->JSON throughput is bound by token count rather than byte count: a document of long quoted
strings converts at ~200 MB/s, while the mixed document above is dominated by per-token dispatch.

Numbers are from one ~31 KB document on one machine (php-ron on PHP 8.5 + JIT; ron-go v0.1.1,
compiled with Go 1.26); run `composer benchmark` (or `php bin/benchmark.php`) to reproduce locally.

## Testing

The suite runs against three pinned upstream corpora (each a git submodule): the official RON
[conformance corpus](https://github.com/starfederation/ron) (exact RON <-> JSON byte matches plus
canonical SHA-256 hashes, plus the typed-vocabulary fixtures for rendering and validation), its
RFC 8785 corpus, and [nst/JSONTestSuite](https://github.com/nst/JSONTestSuite), whose every valid
document is round-tripped through both `fromJson`/`toJson` and `encode`/`decode` to prove conversion
is lossless. Static analysis runs at PHPStan level 9 with php-cs-fixer.

## Development

`composer install` initializes and updates the corpus submodules automatically (via a
post-install hook), so the following is enough after cloning:

```bash
composer install
composer test     # or: composer check  (lint + phpstan + test)
```
