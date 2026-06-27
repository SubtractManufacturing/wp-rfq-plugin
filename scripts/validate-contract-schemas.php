#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validates contract fixture JSON against JSON Schema files in tests/contract/schemas/.
 */

$root = dirname(__DIR__);

/** @var list<array{schema: string, fixture: string}> */
$pairs = [
    ['schema' => 'session-create.schema.json', 'fixture' => 'session-create.json'],
    ['schema' => 'health.schema.json', 'fixture' => 'health-ok.json'],
    ['schema' => 'contact.schema.json', 'fixture' => 'contact.json'],
    ['schema' => 'upload-urls.schema.json', 'fixture' => 'upload-urls.json'],
    ['schema' => 'draft.schema.json', 'fixture' => 'draft.json'],
    ['schema' => 'submit.schema.json', 'fixture' => 'submit.json'],
];

$errors = [];

foreach ($pairs as $pair) {
    $schema_path  = $root . '/tests/contract/schemas/' . $pair['schema'];
    $fixture_path = $root . '/tests/contract/fixtures/' . $pair['fixture'];

    $schema  = json_decode((string) file_get_contents($schema_path), true);
    $fixture = json_decode((string) file_get_contents($fixture_path), true);

    if (! is_array($schema) || ! is_array($fixture)) {
        $errors[] = $pair['fixture'] . ': invalid JSON';
        continue;
    }

    $pair_errors = validate_against_schema($fixture, $schema, $pair['fixture']);
    $errors      = array_merge($errors, $pair_errors);
}

if ($errors !== []) {
    fwrite(STDERR, "Contract schema validation failed:\n");

    foreach ($errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }

    exit(1);
}

echo 'Contract schema validation OK (' . count($pairs) . " fixtures).\n";
exit(0);

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $schema
 * @return list<string>
 */
function validate_against_schema(array $data, array $schema, string $label, string $path = ''): array
{
    $errors = [];

    foreach ($schema['required'] ?? [] as $required_key) {
        if (! array_key_exists($required_key, $data)) {
            $errors[] = "{$label}{$path}: missing required property {$required_key}";
        }
    }

    if (($schema['additionalProperties'] ?? true) === false) {
        foreach (array_keys($data) as $key) {
            if (! isset($schema['properties'][$key])) {
                $errors[] = "{$label}{$path}: unexpected property {$key}";
            }
        }
    }

    foreach ($schema['properties'] ?? [] as $property => $property_schema) {
        if (! array_key_exists($property, $data)) {
            continue;
        }

        $value = $data[$property];
        $errors = array_merge(
            $errors,
            validate_value($value, $property_schema, $label, $path === '' ? ".{$property}" : "{$path}.{$property}")
        );
    }

    return $errors;
}

/**
 * @param mixed $value
 * @param array<string, mixed> $schema
 * @return list<string>
 */
function validate_value(mixed $value, array $schema, string $label, string $path): array
{
    $errors = [];

    if (isset($schema['type'])) {
        $types = is_array($schema['type']) ? $schema['type'] : [$schema['type']];

        if (! type_matches($value, $types)) {
            $errors[] = "{$label}{$path}: expected type " . implode('|', $types);

            return $errors;
        }
    }

    if (is_string($value)) {
        if (isset($schema['minLength']) && strlen($value) < (int) $schema['minLength']) {
            $errors[] = "{$label}{$path}: string too short";
        }

        if (isset($schema['pattern']) && ! preg_match('#' . $schema['pattern'] . '#', $value)) {
            $errors[] = "{$label}{$path}: pattern mismatch";
        }

        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = "{$label}{$path}: not in enum";
        }
    }

    if (is_array($value) && isset($schema['properties'])) {
        $errors = array_merge($errors, validate_against_schema($value, $schema, $label, $path));
    }

    return $errors;
}

/**
 * @param list<string> $types
 */
function type_matches(mixed $value, array $types): bool
{
    foreach ($types as $type) {
        $matches = match ($type) {
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'object' => is_array($value),
            'null' => $value === null,
            default => false,
        };

        if ($matches) {
            return true;
        }
    }

    return false;
}
