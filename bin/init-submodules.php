<?php

declare(strict_types=1);

/*
 * Initialize the conformance corpus git submodule for local development.
 *
 * Wired into Composer's post-install-cmd / post-update-cmd so a fresh checkout has
 * the test fixtures without a manual `git submodule update --init`. It is a no-op
 * when the package is installed as a dependency (no .git) or already initialized.
 */

$root = dirname(__DIR__);

if (!file_exists($root . '/.git')) {
    return; // installed as a dependency, not a git checkout
}
if (is_file($root . '/tests/corpus/ron/testdata/conformance/manifest.json')) {
    return; // submodule already present
}

fwrite(\STDOUT, "Initializing conformance corpus submodule...\n");
passthru('git -C ' . escapeshellarg($root) . ' submodule update --init --recursive', $exitCode);
if ($exitCode !== 0) {
    fwrite(\STDERR, "warning: could not initialize the corpus submodule; tests need it (git required)\n");
}
