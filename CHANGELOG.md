# Changelog

All notable changes to this project are documented here. This project adheres to
[Semantic Versioning](https://semver.org) and the
[Keep a Changelog](https://keepachangelog.com) format.

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
