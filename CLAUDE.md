# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mbolli/php-ron` is a performance-focused PHP implementation of RON (Readable Object Notation):
a JSON-equivalent value model with lighter syntax (elided root braces, bare strings, optional
commas, repeated-quote strings with no escapes). It is a behavioral port of the Go reference
[ron-go](https://github.com/starfederation/ron-go) — when in doubt about correct behavior, the
Go source is the spec, and outputs must match the upstream conformance corpus byte-for-byte.

## Commands

```bash
composer install        # also auto-inits the corpus submodules (post-install hook)
composer test           # PHPUnit
composer check          # lint (cs-fixer dry-run) + phpstan + test  — run before committing
composer fix            # apply php-cs-fixer
composer phpstan        # PHPStan level 9
composer benchmark      # throughput vs the Go reference (runs with OPcache+JIT)

# single test / filter
vendor/bin/phpunit --filter JsonSuiteTest
vendor/bin/phpunit --filter testValidJsonRoundTrips tests/JsonSuiteTest.php
```

Composer may not be on PATH in this environment; use `php composer.phar <cmd>` if `composer` is
missing. Always run the dev tools through the project's composer scripts or `vendor/bin` (not any
globally installed PHPStan/cs-fixer/PHPUnit).

## Architecture

The public surface is the static facade `src/Ron.php` (`toJson`, `fromJson`, `encode`, `decode`,
`canonicalRon`, `canonicalHash`, `canonicalJson`). Everything else is an internal collaborator.

**Two deliberately different conversion strategies** (mirroring ron-go):

- **RON -> JSON is streaming** (`RonToJson extends Scanner`): parses RON and writes JSON bytes
  directly, no intermediate tree. `Scanner` holds the shared low-level RON scanning primitives.
- **JSON -> RON is tree-based**: `JsonParser` builds a value model, `RonRenderer` walks it. A tree
  is required because pretty output and the inline-<=80-byte heuristic need look-ahead.

**The value model** (what the renderer/encoder consume): `null | bool | string | RonNumber |
list<mixed> | RonObject`.
- `Value/RonNumber` carries number **source text** verbatim — never collapse numbers to PHP floats;
  large integers and exponent forms must survive RON<->JSON unchanged.
- `Value/RonObject` is an insertion-ordered string-keyed map with last-wins-at-last-position dedup.
  It is a class (not a PHP array) specifically because PHP coerces numeric-string keys like `"123"`
  to ints, which would corrupt keys and ordering.
- `Encoder` converts arbitrary PHP values (scalars/arrays/objects/JsonSerializable) into this model;
  it backs both `Ron::encode()` and the typed-value render hook. It rejects non-finite floats and
  has a 512 depth guard.

**Canonical key order** is RFC 8785 UTF-16 code-unit order, centralized in `Canonical`. Key insight:
plain `strcmp` of UTF-8 already equals UTF-16 order for all BMP characters; only supplementary
characters (4-byte UTF-8, lead byte >= 0xF0) need UTF-16BE conversion. `Canonical::sortKeyedValues`
uses `array_multisort` (C sort, no per-comparison PHP callback).

`Rfc8785` is a separate JSON-canonicalization path (JCS): it normalizes numbers to ECMAScript
double serialization, rejects duplicate keys and lone surrogates — distinct from the number-text-
preserving compact JSON renderer. `JsonString` is the shared JSON string escaper; `Utf8` decodes runes.

`hash('xxh128', ...)` is byte-identical to the spec's "XXH3-128, seed 0" — the canonical hash needs
no external library.

Do **not** use `json_decode` on the JSON->RON path: it loses number text, coerces numeric keys to
ints, and reorders duplicate keys. The hand-rolled `JsonParser` exists for exactly these reasons.

**Typed vocabularies** (`src/Vocabulary/`) are an optional semantic layer over the value model. A
typed value is a single-key object whose key starts with `#` (e.g. `{#utc ...}`). Two distinct,
independent concerns:

- **Rendering is always on and purely syntactic** (`RonRenderer::writeTaggedObject`): a single
  `#`-prefixed key collapses the wrapper (the `{#tag ` prefix is transparent to indentation, `}}`
  closes together) and lets the payload inline as a multi-key object/array. No vocabulary knowledge
  is involved; it matches ron-go `render.go` regardless of which vocabularies are enabled.
- **Validation is opt-in-beyond-core** (`VocabularyValidator` walks the tree post-parse). `Ron`'s
  `DEFAULT_VOCABULARIES` enables only core; callers pass more URIs (or `[]` to disable). It is
  **validation-only**: payloads are checked but the value model is preserved, so round-trips stay
  lossless. The sole transform is `#vox`, whose non-empty `cells` become a `Value/MultilineList`
  (forced multiline, mirroring ron-go's `multilineArray`). `toJson` (RON->JSON) is intentionally
  **not** validated — it streams without a tree.

`VocabularyRegistry` maps each tag to its owning URI + a validator closure
(`fn(mixed $payload, VocabularyValidator): mixed`): it returns `true` to accept the payload, `false`
to reject it, or any other value to replace it (a transform, e.g. `#vox` -> `MultilineList`); it may
also throw, or return `VocabularyRegistry::replace($v)` to set a literal boolean payload. The walker
(`VocabularyValidator`) discriminates that return value. `official()` registers
the seven built-ins (core/time/network/math/spatial are
ported from ron-go's `vocabulary_*.go`; geo/color are written from `docs/vocabularies.md`, since
ron-go has not built them yet). `register()` adds custom, reverse-DNS-namespaced vocabularies.
Per-tag payload contracts live in `docs/vocabularies.md`; the Go source is still the spec for the
five it implements.

## Performance is a primary goal

The hot paths intentionally use native C functions (`strcspn`/`strpos`/`strspn`) over per-character
PHP loops, and avoid `preg_match`/`mb_check_encoding` in tight loops. Before changing a hot path:

- Profile with a **sampling** profiler (Excimer), not xdebug — xdebug's per-call instrumentation
  over-weights call count and misleads under JIT.
- Measure **best-of-N** (min time) with OPcache+JIT enabled; this is a noisy (WSL2) environment and
  single runs swing ~10-20%.
- Note that `strcspn`/`strspn` rebuild a 256-byte mask each call, so a *large* charset mask can be
  slower than the regex it replaces (a real trap hit during optimization).

## Tests and corpora

Fixtures are pinned git submodules under `tests/corpus/` (`ron` = upstream RON conformance + RFC 8785;
`json` = nst/JSONTestSuite). `bin/init-submodules.php` fetches them on `composer install`.

- Golden-file assertions (`ConformanceTest`, `Rfc8785Test`) are **exact byte** matches, incl.
  XXH3-128 hashes. Pretty RON has a trailing newline; compact/pretty JSON do not.
- Round-trip / structural comparisons use the `ComparesJson` trait (recursive key-sort + strict
  compare). Do NOT use PHPUnit's `assertEqualsCanonicalizing` — it value-sorts arrays and scrambles
  associative arrays of mixed-type values.
- `JsonSuiteTest` round-trips every valid JSONTestSuite doc through both `fromJson`/`toJson` and
  `encode`/`decode`; numbers are compared numerically (RON/JSON have one number type).

## Tooling conventions that will bite you

- **PHPStan level 9 + bleedingEdge + type_coverage 100%** (analyzes `src/` only). Class constants must
  be typed (`private const string X = ...`). The value model is genuinely `mixed`, so `no_mixed` is off.
- **php-cs-fixer** (`@PhpCsFixer:risky`) with two deliberate overrides and several gotchas:
  - `mb_str_functions` is **disabled** — this is a byte-oriented parser; `mb_strlen`/`mb_substr` would
    turn byte offsets into char offsets and break everything.
  - The `strict_comparison` rule rewrites `==` to `===`. When you need numeric equality (e.g. `1` vs
    `1.0`), use `($a <=> $b) === 0`, which the fixer leaves alone.
  - `php_unit_strict` rewrites `assertEquals` to `assertSame`; the doc-comment-metadata fixers are
    disabled because PHPUnit 11 deprecates `@coversNothing`/`@internal`.
  - cs-fixer covers `src/`, `tests/`, and `bin/` (the corpus submodule is excluded).

## Conventions

- Brace style is same-line (`final class X {`), enforced by cs-fixer.
- This is a library: `composer.lock` is gitignored; do not commit it.
- Recursive-descent parsers (`JsonParser`, `RonToJson`, `Rfc8785`) enforce a configurable nesting
  cap (`maxDepth`, default `Ron::DEFAULT_MAX_DEPTH` = 512, like `json_encode`/`json_decode`): the
  guard sits at container entry, so over-deep input throws `RonException` instead of overflowing the
  stack. The three parse paths reject at the same boundary (512 levels in, 513 rejected). `RonRenderer`
  has no guard because it only ever walks trees already bounded by `JsonParser` or `Encoder`.
