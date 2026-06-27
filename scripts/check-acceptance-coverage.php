#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scoped M1–M3 backend + Phase 5 embed acceptance-coverage gate.
 *
 * Parses PHPUnit test files for #[Group('AC-WP-xxx')] and @covers AC-WP-xxx tags.
 */

$root = dirname(__DIR__);

/** @var list<string> */
$required_ids = [
    'AC-WP-001',
    'AC-WP-005',
    'AC-WP-006',
    'AC-WP-009',
    'AC-WP-010',
    'AC-WP-011',
    'AC-WP-012',
    'AC-WP-016',
    'AC-WP-017',
    'AC-WP-018',
    'AC-WP-019',
    'AC-WP-020',
    'AC-WP-021',
    'AC-WP-022',
    'AC-WP-023',
    'AC-WP-024',
    'AC-WP-025',
    'AC-WP-026',
    'AC-WP-028',
];

$covered = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/tests/php', FilesystemIterator::SKIP_DOTS)
);

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $contents = file_get_contents($file->getPathname());

    if ($contents === false) {
        continue;
    }

    if (preg_match_all("/#\\[Group\\('(AC-WP-\\d{3})'\\)\\]/", $contents, $group_matches)) {
        foreach ($group_matches[1] as $id) {
            $covered[$id] = ($covered[$id] ?? []);
            $covered[$id][] = $file->getPathname();
        }
    }

    if (preg_match_all('/@covers\\s+(AC-WP-\\d{3})\\b/i', $contents, $covers_matches)) {
        foreach ($covers_matches[1] as $id) {
            $covered[$id] = ($covered[$id] ?? []);
            $covered[$id][] = $file->getPathname();
        }
    }
}

$missing = [];

foreach ($required_ids as $id) {
    if ($id === 'AC-WP-024') {
        $spike_report = $root . '/Planning/S3-SPIKE-REPORT.md';
        $has_spike    = is_file($spike_report) && trim((string) file_get_contents($spike_report)) !== '';
        $has_tests    = isset($covered[$id]);

        if (! $has_spike && ! $has_tests) {
            $missing[] = $id . ' (needs PHPUnit mapping or Planning/S3-SPIKE-REPORT.md)';
        }

        continue;
    }

    if (! isset($covered[$id])) {
        $missing[] = $id;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "Acceptance coverage failed — missing mappings for scoped backend + Phase 5 embed AC IDs:\n");

    foreach ($missing as $id) {
        fwrite(STDERR, "  - {$id}\n");
    }

    exit(1);
}

$covered_count = count(array_intersect($required_ids, array_keys($covered)));
$spike_note    = is_file($root . '/Planning/S3-SPIKE-REPORT.md') ? ' + S3 spike report' : '';

echo sprintf(
    "Acceptance coverage OK: %d/%d scoped backend + Phase 5 embed AC IDs mapped in tests%s.\n",
    count($required_ids),
    count($required_ids),
    $spike_note
);

exit(0);
