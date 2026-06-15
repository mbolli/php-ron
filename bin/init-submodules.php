<?php

declare(strict_types=1);

/*
 * Initialize the test corpus git submodules for local development.
 *
 * Wired into Composer's post-install-cmd / post-update-cmd so a fresh checkout has
 * the test fixtures without a manual `git submodule update --init`. It is a no-op
 * when the package is installed as a dependency (no .git) or already initialized.
 */

$root = dirname(__DIR__);

$sentinels = [
    $root . '/tests/corpus/ron/testdata/conformance/manifest.json',
    $root . '/tests/corpus/json/test_parsing',
];

if (!file_exists($root . '/.git')) {
    return; // installed as a dependency, not a git checkout
}
$present = array_filter($sentinels, 'file_exists');
if (count($present) === count($sentinels)) {
    return; // all submodules already present
}

fwrite(\STDOUT, "Initializing test corpus submodules...\n");
passthru('git -C ' . escapeshellarg($root) . ' submodule update --init --recursive', $exitCode);
if ($exitCode !== 0) {
    fwrite(\STDERR, "warning: could not initialize the corpus submodules; tests need them (git required)\n");
}
