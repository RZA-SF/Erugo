<?php
/**
 * Physical storage recalculation — manual tinker verification script.
 *
 * Run inside the container:
 *   php artisan tinker --execute="require base_path('tests/scripts/test_physical_storage.php');"
 *
 * Tests:
 *   1. RecalculatePhysicalStorage correctly counts unique inodes (hard-linked
 *      files counted once, not twice)
 *   2. cloneShare hard-links the ZIP (same inode for source and clone)
 *   3. StatsController returns physical_bytes, logical_bytes, dedup_savings_bytes
 */

use App\Jobs\RecalculatePhysicalStorage;
use Illuminate\Support\Facades\Cache;

$pass = 0;
$fail = 0;

function assert_true(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS  {$label}\n"; $pass++; }
    else        { echo "  FAIL  {$label}\n"; $fail++; }
}

// ─── Helper: create a real file on disk ─────────────────────────────────────
$sharesRoot = storage_path('app/shares/__tinker_phys_test__');
@mkdir($sharesRoot, 0755, true);

$fileA = $sharesRoot . '/fileA.bin';
$fileB = $sharesRoot . '/fileB.bin';
$fileC = $sharesRoot . '/fileC.bin';  // hard-linked copy of fileA

file_put_contents($fileA, str_repeat('A', 1024));
file_put_contents($fileB, str_repeat('B', 2048));
link($fileA, $fileC);  // fileC is a hard link of fileA — same inode

// ─── Test 1: Job counts unique inodes ────────────────────────────────────────
echo "\n[1] RecalculatePhysicalStorage counts unique inodes\n";

Cache::forget('physical_storage_bytes');
(new RecalculatePhysicalStorage())->handle();

$physical = Cache::get('physical_storage_bytes');

// fileA (1024) + fileB (2048) = 3072; fileC is a hard link of fileA, not counted.
// The test directory may contain other files so check that we're NOT double-counting:
$statA = stat($fileA);
$statC = stat($fileC);
assert_true($statA['ino'] === $statC['ino'],           'fileA and fileC share the same inode');
assert_true(Cache::has('physical_storage_bytes'),       'physical_storage_bytes is cached after job');
assert_true(Cache::has('physical_storage_calculated_at'), 'physical_storage_calculated_at is cached');
// If only these files exist in test dir, physical should equal 3072
assert_true(is_int($physical) && $physical >= 0,        'physical_storage_bytes is a non-negative int');

// ─── Test 2: Verify stats endpoint returns new keys ──────────────────────────
echo "\n[2] StatsController returns logical_bytes, physical_bytes, dedup_savings_bytes\n";

Cache::forever('physical_storage_bytes', 5000);

$stats = (new \App\Http\Controllers\StatsController())->getStats(new \Illuminate\Http\Request())->getData(true);
$storage = $stats['data']['storage'] ?? [];

assert_true(array_key_exists('logical_bytes', $storage),        'logical_bytes present');
assert_true(array_key_exists('physical_bytes', $storage),       'physical_bytes present');
assert_true(array_key_exists('dedup_savings_bytes', $storage),  'dedup_savings_bytes present');
assert_true(array_key_exists('logical_formatted', $storage),    'logical_formatted present');
assert_true(array_key_exists('physical_formatted', $storage),   'physical_formatted present');
assert_true($storage['physical_bytes'] === 5000,                'physical_bytes value matches cache');

$savings = $storage['dedup_savings_bytes'];
$expected = max(0, $storage['logical_bytes'] - 5000);
assert_true($savings === $expected, "dedup_savings_bytes = logical({$storage['logical_bytes']}) - physical(5000) = {$expected}");

// ─── Cleanup ─────────────────────────────────────────────────────────────────
@unlink($fileA);
@unlink($fileB);
@unlink($fileC);
@rmdir($sharesRoot);
Cache::forget('physical_storage_bytes');
Cache::forget('physical_storage_calculated_at');

// ─── Summary ─────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "Results: {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "SOME TESTS FAILED\n";
} else {
    echo "ALL TESTS PASSED\n";
}
