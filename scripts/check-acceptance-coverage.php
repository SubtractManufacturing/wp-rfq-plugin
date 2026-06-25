<?php

declare(strict_types=1);

$required = [
    'AC-WP-006',
    'AC-WP-012',
    'AC-WP-023',
];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(__DIR__ . '/../tests/php', FilesystemIterator::SKIP_DOTS)
);

$contents = '';

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $contents .= file_get_contents($file->getPathname()) . "\n";
    }
}

$missing = [];

foreach ($required as $criterion) {
    if (strpos($contents, $criterion) === false) {
        $missing[] = $criterion;
    }
}

if ($missing !== []) {
    fwrite(STDERR, 'Missing acceptance coverage tags: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Acceptance coverage tags present for M1 scope.' . PHP_EOL);

