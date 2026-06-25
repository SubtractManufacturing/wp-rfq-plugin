<?php

if (!defined('ABSPATH')) {
    exit;
}

class RFQ_Material_Catalog
{
    /**
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
     */
    public static function get_default_catalog(): array
    {
        $path = RFQ_INTAKE_PLUGIN_DIR . 'assets/materials/default.json';

        if (!is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            return [];
        }

        return self::normalize_entries($decoded);
    }

    /**
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
     */
    public static function get_effective_catalog(): array
    {
        $catalog = self::get_default_catalog();
        $overrides_raw = get_option('rfq_material_overrides', '');

        if (!is_string($overrides_raw) || trim($overrides_raw) === '') {
            return $catalog;
        }

        $overrides = json_decode($overrides_raw, true);

        if (!is_array($overrides)) {
            return $catalog;
        }

        return self::apply_overrides($catalog, $overrides);
    }

    /**
     * @param list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}> $catalog
     * @param array<string, mixed> $overrides
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
     */
    private static function apply_overrides(array $catalog, array $overrides): array
    {
        $disabled = self::collect_disabled_ids($overrides);
        $renamed = self::collect_renamed_labels($overrides);
        $result = [];

        foreach ($catalog as $entry) {
            $id = $entry['id'];

            if (isset($disabled[$id])) {
                continue;
            }

            if (isset($renamed[$id])) {
                $entry['label'] = $renamed[$id];
            }

            $result[] = $entry;
        }

        foreach (self::normalize_entries(self::collect_added_entries($overrides)) as $entry) {
            if (!isset($disabled[$entry['id']])) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, true>
     */
    private static function collect_disabled_ids(array $overrides): array
    {
        $disabled = [];

        foreach (['disabled', 'disable'] as $key) {
            if (!isset($overrides[$key]) || !is_array($overrides[$key])) {
                continue;
            }

            foreach ($overrides[$key] as $id) {
                if (is_string($id) && $id !== '') {
                    $disabled[$id] = true;
                }
            }
        }

        return $disabled;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    private static function collect_renamed_labels(array $overrides): array
    {
        $renamed = [];

        foreach (['renamed', 'rename'] as $key) {
            if (!isset($overrides[$key]) || !is_array($overrides[$key])) {
                continue;
            }

            foreach ($overrides[$key] as $id => $label) {
                if (is_string($id) && $id !== '' && is_string($label) && $label !== '') {
                    $renamed[$id] = $label;
                }
            }
        }

        return $renamed;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return list<mixed>
     */
    private static function collect_added_entries(array $overrides): array
    {
        $added = [];

        foreach (['added', 'add'] as $key) {
            if (!isset($overrides[$key]) || !is_array($overrides[$key])) {
                continue;
            }

            $added = array_merge($added, $overrides[$key]);
        }

        return $added;
    }

    /**
     * @param list<mixed> $entries
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
     */
    private static function normalize_entries(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $id = isset($entry['id']) ? (string) $entry['id'] : '';
            $label = isset($entry['label']) ? (string) $entry['label'] : '';

            if ($id === '' || $label === '') {
                continue;
            }

            $aliases = [];

            if (isset($entry['aliases']) && is_array($entry['aliases'])) {
                foreach ($entry['aliases'] as $alias) {
                    if (is_string($alias) && $alias !== '') {
                        $aliases[] = $alias;
                    }
                }
            }

            $normalized[] = [
                'id' => $id,
                'label' => $label,
                'aliases' => $aliases,
                'show_in_dropdown' => !empty($entry['show_in_dropdown']),
            ];
        }

        return $normalized;
    }
}
