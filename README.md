# php-ron

[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-200%20passing-brightgreen)](tests/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen)](.phpstan.neon)
[![Code style](https://img.shields.io/badge/code%20style-php--cs--fixer-brightgreen)](.php-cs-fixer.php)

A performance-focused PHP implementation of [RON (Readable Object Notation)](https://github.com/starfederation/ron).

RON keeps JSON's value model but drops avoidable syntax: top-level object braces can be
elided, strings can be bare, commas are optional separators, and quoted strings use repeated
`'`/`"` delimiters with no backslash escapes. It converts losslessly to and from JSON and is
cheaper for humans and LLMs to read and write.

Reach for it wherever you'd use JSON but a person or an LLM authors or reads the data — config
files, fixtures, logs, and prompt/context payloads where the saved quotes and braces add up to
real tokens. Because every RON document maps 1:1 to a JSON value, you can adopt it only at those
edges (author or display RON, keep storing and transmitting JSON) without changing your data model.

This library is a port of the reference Go implementation
([ron-go](https://github.com/starfederation/ron-go)) and passes the upstream conformance
corpus (`testdata/conformance`) and the RFC 8785 corpus (`testdata/rfc8785`).

## Requirements

- PHP >= 8.1 (`ext-hash` for `xxh128`, `ext-mbstring`)

## Install

```bash
composer require mbolli/php-ron
```

## Usage

```php
use Mbolli\Ron\Ron;

// Encode/decode arbitrary PHP values, like json_encode/json_decode
Ron::encode(['name' => 'Ada', 'active' => true]); // active true\nname Ada\n
Ron::encode($data, pretty: false);                // compact RON
Ron::decode("name Ada\nactive true");             // ['active' => true, 'name' => 'Ada']

// RON -> JSON (compact by default, canonical key order)
Ron::toJson("name Ada\nactive true");          // {"active":true,"name":"Ada"}
Ron::toJson($ron, pretty: true);                // multiline JSON

// JSON -> RON (pretty by default, canonical key order)
Ron::fromJson('{"name":"Ada","active":true}'); // active true\nname Ada\n
Ron::fromJson($json, pretty: false);            // compact RON

// Canonical RON and its unseeded XXH3-128 hash (32 lowercase hex)
Ron::canonicalRon($json);
Ron::canonicalHash($json);

// RFC 8785 (JCS) canonical JSON
Ron::canonicalJson($json);
```

Invalid input throws `Mbolli\Ron\RonException`.

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

## Scope

This implementation matches ron-go: RON<->JSON conversion (compact/pretty/canonical), RFC 8785
canonical JSON, and the typed-value render hook. Vocabulary-tagged objects (e.g. `{"#utc": ...}`)
are preserved as ordinary JSON objects; there is no vocabulary registry or validation.

## Performance

The hot paths scan bytes with native C functions (`strcspn`/`strpos`) instead of per-character
PHP loops, sort object keys with `array_multisort` (no per-comparison PHP callback), and stream
RON->JSON directly without an intermediate tree. The canonical hash uses native `hash('xxh128')`.

Measured throughput (OPcache + JIT), flat and linear from ~1.5 KB to ~320 KB, on the same input
against the Go reference ([ron-go](https://github.com/starfederation/ron-go), compiled):

| Conversion            | php-ron  | ron-go (Go) | vs ron-go    |
| --------------------- | -------- | ----------- | ------------ |
| JSON -> RON (compact) | ~19 MB/s | ~17 MB/s    | ~on par      |
| JSON -> RON (pretty)  | ~16 MB/s | ~17 MB/s    | ~on par      |
| RON -> JSON (compact) | ~15 MB/s | ~81 MB/s    | ~5.7x slower |
| canonical hash        | ~19 MB/s | —           |              |

php-ron is on par with the Go reference on JSON->RON (within benchmark noise): ron-go decodes JSON
through Go's `encoding/json`, whereas php-ron uses a hand-rolled scanner. On RON->JSON, ron-go
streams in near-zero-allocation compiled code and is ~5.7x faster — the realistic interpreter tax
for that direction. A 1-10 KB payload still converts in well under a millisecond.

Numbers are from one ~31 KB document on one machine; run `composer benchmark` (or
`php bin/benchmark.php`) to reproduce locally.

## Testing

The suite runs against three pinned upstream corpora (each a git submodule): the official RON
[conformance corpus](https://github.com/starfederation/ron) (exact RON <-> JSON byte matches plus
canonical XXH3-128 hashes), its RFC 8785 corpus, and [nst/JSONTestSuite](https://github.com/nst/JSONTestSuite),
whose every valid document is round-tripped through both `fromJson`/`toJson` and `encode`/`decode`
to prove conversion is lossless. Static analysis runs at PHPStan level 9 with php-cs-fixer.

## Development

`composer install` initializes and updates the corpus submodules automatically (via a
post-install hook), so the following is enough after cloning:

```bash
composer install
composer test     # or: composer check  (lint + phpstan + test)
```
