<?php

declare(strict_types=1);

namespace Mbolli\Ron;

/**
 * Output mode for every conversion (port of ron-go's OutputMode).
 *
 * The three modes are exclusive; there is no separate "canonical" flag. Pretty and
 * compact differ only in whitespace and both preserve source/member order. Canonical
 * is a different contract, not merely sorted compact output: it applies RFC 8785 and
 * the RFC 7493 I-JSON value constraints before rendering.
 */
enum RonMode: string {
    /** Multiline output preserving member order. The default. */
    case Pretty = 'pretty';

    /** Single-line output preserving member order. */
    case Compact = 'compact';

    /**
     * Canonical output for the target format: RFC 8785 JSON, or compact RON for an
     * RFC 8785 / I-JSON value. Duplicate decoded names, invalid Unicode, Unicode
     * noncharacters and non-finite numbers are rejected; numbers are re-serialized
     * with the ECMAScript algorithm (input number spelling is not preserved) and
     * object keys are sorted recursively by UTF-16 code unit.
     */
    case Canonical = 'canonical';
}
