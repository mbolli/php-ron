# Changelog

All notable changes to this project are documented here. This project adheres to
[Semantic Versioning](https://semver.org) and the
[Keep a Changelog](https://keepachangelog.com) format.

## [0.5.0] - 2026-08-31

Tracks [RON spec v0.4.0](https://github.com/starfederation/ron/releases/tag/v0.4.0) and
[ron-go v0.1.1](https://github.com/starfederation/ron-go). The pinned conformance corpus moves
from v0.1.0 to v0.4.0. This release is breaking in both the API and the output bytes.

### Added

- **JSON escapes in every string form.** Bare strings, `'`/`"` strings, repeated-delimiter
  strings, comma-prefixed tokens and object keys all accept `\" \\ \/ \b \f \n \r \t \uXXXX`,
  including surrogate pairs. An escape is a single scanner atom, so `a\u0020b` stays one token.
  Type classification happens before decoding, so `tr\u0075e` is the string `"true"`.
  Unknown, truncated and non-hex escapes, unpaired surrogates and raw U+0000-U+001F in string
  content are now rejected. The renderer escapes backslash and C0 controls before deciding
  bare vs quoted, so `"a\nb"` renders as bare `a\nb` and a literal backslash is doubled.
- `RonMode` enum (`Pretty`, `Compact`, `Canonical`) replacing the `$pretty`/`$canonical` flags.
- Real canonical mode. `RonMode::Canonical` applies the RFC 8785 and RFC 7493 (I-JSON) contract:
  duplicate decoded member names, invalid Unicode, Unicode noncharacters and numbers whose
  IEEE 754 conversion is non-finite are rejected, and numbers are re-serialized with the
  ECMAScript algorithm.
- `Ron::format()` re-renders RON source in a given mode (RON -> RON), which is how canonical RON
  and its hash are derived from RON rather than JSON input.
- `set` vocabulary: `#set` (deduplicated and sorted by each element's RFC 8785 canonical JSON
  bytes) and `#bits` (uint32 indexes normalized to ascending, merged, inclusive ranges).
- `#topo` (TopoJSON topology) in the geo vocabulary. Validated but never expanded: shared arcs
  stay indexed, negative indexes stay unreversed, quantized coordinates keep their encoding.
- `RonObject::has()` and `Utf8::encodeRune()`.

### Changed

- **BREAKING:** `toJson()`, `fromJson()`, `encode()` and `RonRenderer::__construct()` take a
  `RonMode` instead of `bool $pretty, bool $canonical`.
- **BREAKING:** `toJson()` now defaults to pretty output, matching the spec's `defaultMode` and
  ron-go. Pass `RonMode::Compact` for the previous default.
- **BREAKING:** pretty and compact output preserve source member order. Only `RonMode::Canonical`
  sorts keys.
- **BREAKING:** canonical output no longer preserves number spelling (`1E2` canonicalizes to
  `100`, `9007199254740993` to `9007199254740992`). Pretty and compact still preserve it.
- RFC 8785 canonicalization accepts a finite number that rounds during IEEE 754 conversion; only
  a non-finite conversion is an error. The former "integer is not exactly representable"
  rejection is gone, following the corpus.
- N-quoted strings are delimiter-aware: a same-quote run shorter than the opening run and every
  occurrence of the other quote byte are content. The `(n - 2) % 3` compatibility form is now
  apostrophe-only; a double-quote run never takes it.
- A comma directly after an object key starts the value token (`k ,foo` is the string `",foo"`)
  instead of being skipped as a separator.
- Bare tokens end at Unicode whitespace, not only at ASCII delimiters.
- `#rx` payloads carry doubled backslashes on the wire now that every RON string decodes escapes:
  JavaScript `\d` is written as RON `\\d`.

### Performance

Measured best-of-N on one ~31 KB document with OPcache + JIT, against v0.4.1 on the same
machine. Pretty and compact got faster because they no longer sort object keys, and the string
renderer now answers "needs escaping?" and "needs quoting?" with one `strcspn` over a combined
mask instead of two scans plus an `mb_check_encoding`. The token scanner keeps its old 11-byte
delimiter mask for sources that contain no escape, no raw control and no Unicode-whitespace lead
byte, decided by a single pass in `Scanner::setSource()` — without it the 45-byte escape-aware
mask cost ~20% on token-heavy input, because `strcspn` rebuilds a 256-byte table per call.

| Conversion | v0.4.1 | v0.5.0 |
| --- | --- | --- |
| JSON -> RON (compact) | ~26 MB/s | ~29 MB/s |
| JSON -> RON (pretty) | ~20 MB/s | ~26 MB/s |
| RON -> JSON (compact) | ~20 MB/s | ~19 MB/s |
| canonical hash | ~26 MB/s | ~13 MB/s |

The canonical hash is ~2x slower and stays that way by design: it used to be compact rendering
with sorted keys, and now validates the whole RFC 8785 / I-JSON contract and re-serializes every
number. Documents made of long quoted strings convert ~9% slower (~222 -> ~203 MB/s) because the
content scan is escape-aware; that path runs an order of magnitude above the token-bound one, so
it was left alone.

### Removed

- **BREAKING:** `#lla` (LngLatAlt) from the spatial vocabulary, removed upstream. Geographic
  positions belong in `#geo`, whose positions accept an optional third altitude element.

### Fixed

- Unicode noncharacters (U+FDD0-U+FDEF and every plane's U+xFFFE/U+xFFFF) are rejected in RFC 8785
  canonical JSON, whether written directly or via `\uXXXX`.

## [0.4.1] - 2026-06-22

### Changed

- Minimum PHP raised to 8.3. The codebase already relied on typed class constants (an 8.3
  feature), so `composer.json`'s previous `>=8.1` was inaccurate; it now declares `>=8.3`.
  Tests run against PHP 8.3, 8.4, and 8.5 in CI.

## [0.4.0] - 2026-06-22

### Added

- `#rx` core vocabulary tag (JavaScript RegExp). Validates `[source]` or `[source, flags]`
  payloads: flags must be canonical (sorted, unique, drawn from `dgimsuvy`, with `u` and `v`
  mutually exclusive) and the source must convert (JS escapes) and compile. Matches upstream RON
  ([PR #22](https://github.com/starfederation/ron/pull/22)) and ron-go
  ([PR #29](https://github.com/starfederation/ron-go/pull/29)). Validation-only, so `#rx` values
  round-trip losslessly. PHP has no RE2, so PCRE backs the source compile check; it diverges from
  ron-go only on backreferences/lookaround, which no conformance case exercises.

### Changed

- Updated the pinned RON conformance corpus to include the `#rx` fixtures.

## [0.3.0] - 2026-06-15

### Changed

- **Breaking:** the canonical hash is now SHA-256 instead of unseeded XXH3-128. `Ron::canonicalHash`
  returns 64 lowercase hex digits (was 32), computed as `hash('sha256', ...)` of the canonical RON
  bytes. This matches upstream RON ([PR #16](https://github.com/starfederation/ron/pull/16)); any
  consumer storing or comparing previously-produced hashes must recompute them.

## [0.2.0] - 2026-06-15

### Added

- Typed vocabularies: optional, opt-in-beyond-core validation of typed values (single-key
  objects whose key starts with `#`, e.g. `{#utc ...}`) against the official vocabularies —
  core, time, network, math, spatial, color, and geo — plus user-registered custom
  vocabularies. New `Ron::validate()`, and `vocabularies` / `registry` options on
  `Ron::fromJson`, `Ron::canonicalRon`, and `Ron::canonicalHash`. The core vocabulary is
  validated by default; pass `vocabularies: []` to disable. Validation preserves the value
  model, so typed values round-trip losslessly; `Ron::toJson` is not validated.
- `Mbolli\Ron\Vocabulary\VocabularyRegistry` (`official()`, `register()`, `replace()`) for
  declaring custom, reverse-DNS-namespaced vocabularies. A validator returns `true`/`false`
  to accept/reject, any other value to transform the payload, or `replace($v)` to set a
  literal (e.g. boolean) payload.

### Changed

- A single `#`-prefixed key now renders in collapsed typed form (`{#tag payload}`), with the
  wrapper transparent to indentation and multi-key payloads allowed to inline, matching the
  updated RON spec and ron-go. This changes the rendered RON for documents containing
  single-key `#`-objects.

## [0.1.1] - 2026-06-15

### Added

- `Ron::tokenize()`: a lenient, role-aware RON tokenizer (keys, values, numbers,
  literals, and structure), suitable for syntax highlighting and tooling.
- Configurable `maxDepth` (default `Ron::DEFAULT_MAX_DEPTH` = 512) on every parse entry
  point. Input nested deeper than the cap throws `RonException` instead of overflowing the
  stack or hanging; the `JsonParser`, `RonToJson`, and `Rfc8785` paths reject at the same
  boundary.

### Changed

- RON rendering is now linear in input size.

## [0.1.0] - 2026-06-15

Initial release. A performance-focused PHP implementation of RON (Readable Object
Notation), behaviorally ported from the Go reference [ron-go](https://github.com/starfederation/ron-go)
and verified against the upstream conformance corpus.

### Added

- RON <-> JSON conversion: `Ron::toJson` (streaming RON -> JSON) and `Ron::fromJson`
  (tree-based JSON -> RON), with pretty/compact and canonical-key-order options.
- `Ron::encode` / `Ron::decode` for arbitrary PHP values, like `json_encode`/`json_decode`,
  preserving number source text on the conversion paths.
- Canonicalization: `Ron::canonicalRon`, `Ron::canonicalHash` (XXH3-128 of the canonical
  RON bytes), and `Ron::canonicalJson` (RFC 8785 / JCS).
