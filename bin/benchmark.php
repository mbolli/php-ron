<?php

declare(strict_types=1);

/*
 * Throughput benchmark for RON <-> JSON conversion, with a native json_* baseline.
 * Usage: php bin/benchmark.php [iterations]
 * For representative numbers run with OPcache + JIT enabled, e.g.:
 *   php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=tracing bin/benchmark.php
 *
 * Throughput is flat and linear in input size from ~1.5 KB to ~320 KB: JSON->RON
 * and the canonical hash run ~18 MB/s, RON->JSON ~14 MB/s. The heavy scanning is
 * delegated to native strcspn/strpos and array_multisort, leaving PHP to do only
 * per-token dispatch. A 1-10 KB payload converts in well under a millisecond. See
 * the README "Performance" section for a comparison against the Go reference.
 */

require __DIR__ . '/../vendor/autoload.php';

use Mbolli\Ron\Ron;

$iterations = (int) ($argv[1] ?? 5000);

function makeDocument(int $users): string {
    $rows = [];
    for ($i = 0; $i < $users; ++$i) {
        $rows[] = [
            'id' => $i,
            'name' => 'User ' . $i,
            'active' => $i % 2 === 0,
            'score' => $i * 1.5,
            'roles' => ['admin', 'writer', 'reviewer'],
            'meta' => ['created' => '2026-01-01T00:00:00Z', 'tags' => ['a', 'b', 'c']],
        ];
    }

    return (string) json_encode(['users' => $rows, 'count' => $users]);
}

function bench(string $label, int $iterations, int $bytes, callable $fn): void {
    $fn(); // warm up
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; ++$i) {
        $fn();
    }
    $elapsed = (hrtime(true) - $start) / 1e9;
    $mbps = $bytes * $iterations / $elapsed / (1024 * 1024);
    printf("  %-24s %7.1f MB/s  %9.1f ops/s\n", $label, $mbps, $iterations / $elapsed);
}

foreach ([10, 200, 2000] as $users) {
    $json = makeDocument($users);
    $bytes = strlen($json);
    $ron = Ron::fromJson($json, pretty: false);
    $iter = max(200, (int) ($iterations * 200 / max(1, $users)));

    printf("\ndocument: %d users, %d bytes JSON, %d iterations\n", $users, $bytes, $iter);
    bench('RON -> JSON (compact)', $iter, strlen($ron), static fn () => Ron::toJson($ron));
    bench('JSON -> RON (compact)', $iter, $bytes, static fn () => Ron::fromJson($json, pretty: false));
    bench('JSON -> RON (pretty)', $iter, $bytes, static fn () => Ron::fromJson($json));
    bench('canonical hash', $iter, $bytes, static fn () => Ron::canonicalHash($json));
}
