# Changelog

All notable changes to this project are documented here. This project adheres to
[Semantic Versioning](https://semver.org) and the
[Keep a Changelog](https://keepachangelog.com) format.

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
- Configurable `maxDepth` (default `Ron::DEFAULT_MAX_DEPTH` = 512) on every parse entry
  point. Input nested deeper than the cap now throws `RonException` instead of overflowing
  the stack or hanging; the `JsonParser`, `RonToJson`, and `Rfc8785` paths reject at the
  same boundary.
