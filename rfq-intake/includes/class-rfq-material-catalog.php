<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Material_Catalog {

    /**
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool, group: string, type: string, guidance: string, finishes: list<string>, colors: list<string>}>
     */
    public static function get_default_catalog(): array {
        $path = RFQ_INTAKE_PLUGIN_DIR . 'assets/materials/default.json';

        if ( ! is_readable( $path ) ) {
            return [];
        }

        $decoded = json_decode( (string) file_get_contents( $path ), true );

        if ( ! is_array( $decoded ) ) {
            return [];
        }

        return self::normalize_entries( $decoded );
    }

    /**
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool, group: string, type: string, guidance: string, finishes: list<string>, colors: list<string>}>
     */
    public static function get_effective_catalog(): array {
        $catalog       = self::get_default_catalog();
        $overrides_raw = get_option( 'rfq_material_overrides', '' );

        if ( ! is_string( $overrides_raw ) || trim( $overrides_raw ) === '' ) {
            return $catalog;
        }

        $overrides = json_decode( $overrides_raw, true );

        if ( ! is_array( $overrides ) ) {
            return $catalog;
        }

        return self::apply_overrides( $catalog, $overrides );
    }

    /**
     * @param list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool, group: string, type: string, guidance: string, finishes: list<string>, colors: list<string>}> $catalog
     * @param array<string, mixed> $overrides
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool, group: string, type: string, guidance: string, finishes: list<string>, colors: list<string>}>
     */
    private static function apply_overrides( array $catalog, array $overrides ): array {
        $disabled = self::collect_disabled_ids( $overrides );
        $renamed  = self::collect_renamed_labels( $overrides );
        $result   = [];

        foreach ( $catalog as $entry ) {
            $id = $entry['id'];

            if ( isset( $disabled[ $id ] ) ) {
                continue;
            }

            if ( isset( $renamed[ $id ] ) ) {
                $entry['label'] = $renamed[ $id ];
            }

            $result[] = $entry;
        }

        $existing_ids = [];

        foreach ( $result as $entry ) {
            $existing_ids[ $entry['id'] ] = true;
        }

        foreach ( self::normalize_entries( self::collect_added_entries( $overrides ) ) as $entry ) {
            if ( isset( $disabled[ $entry['id'] ] ) || isset( $existing_ids[ $entry['id'] ] ) ) {
                continue;
            }

            $result[]                     = $entry;
            $existing_ids[ $entry['id'] ] = true;
        }

        return $result;
    }

    /**
     * @param mixed $overrides
     */
    public static function is_valid_override_shape( mixed $overrides ): bool {
        if ( ! is_array( $overrides ) ) {
            return false;
        }

        foreach ( [ 'disabled', 'disable' ] as $key ) {
            if ( ! isset( $overrides[ $key ] ) ) {
                continue;
            }

            if ( ! is_array( $overrides[ $key ] ) ) {
                return false;
            }

            foreach ( $overrides[ $key ] as $id ) {
                if ( ! is_string( $id ) || $id === '' ) {
                    return false;
                }
            }
        }

        foreach ( [ 'renamed', 'rename' ] as $key ) {
            if ( ! isset( $overrides[ $key ] ) ) {
                continue;
            }

            if ( ! is_array( $overrides[ $key ] ) ) {
                return false;
            }

            foreach ( $overrides[ $key ] as $id => $label ) {
                if ( ! is_string( $id ) || $id === '' || ! is_string( $label ) || $label === '' ) {
                    return false;
                }
            }
        }

        foreach ( [ 'added', 'add' ] as $key ) {
            if ( ! isset( $overrides[ $key ] ) ) {
                continue;
            }

            if ( ! is_array( $overrides[ $key ] ) ) {
                return false;
            }

            foreach ( $overrides[ $key ] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    return false;
                }

                $id    = isset( $entry['id'] ) ? (string) $entry['id'] : '';
                $label = isset( $entry['label'] ) ? (string) $entry['label'] : '';

                if ( $id === '' || $label === '' ) {
                    return false;
                }

                if ( isset( $entry['aliases'] ) && ! is_array( $entry['aliases'] ) ) {
                    return false;
                }

                if ( isset( $entry['aliases'] ) ) {
                    foreach ( $entry['aliases'] as $alias ) {
                        if ( ! is_string( $alias ) || $alias === '' ) {
                            return false;
                        }
                    }
                }

                foreach ( [ 'group', 'type', 'guidance' ] as $text_field ) {
                    if ( isset( $entry[ $text_field ] ) && ! is_string( $entry[ $text_field ] ) ) {
                        return false;
                    }
                }

                foreach ( [ 'finishes', 'colors' ] as $list_field ) {
                    if ( ! isset( $entry[ $list_field ] ) ) {
                        continue;
                    }

                    if ( ! is_array( $entry[ $list_field ] ) ) {
                        return false;
                    }

                    foreach ( $entry[ $list_field ] as $item ) {
                        if ( ! is_string( $item ) || $item === '' ) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function normalize_string_list( mixed $value ): array {
        $list = [];

        if ( ! is_array( $value ) ) {
            return $list;
        }

        foreach ( $value as $item ) {
            if ( is_string( $item ) && $item !== '' ) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, true>
     */
    private static function collect_disabled_ids( array $overrides ): array {
        $disabled = [];

        foreach ( [ 'disabled', 'disable' ] as $key ) {
            if ( ! isset( $overrides[ $key ] ) || ! is_array( $overrides[ $key ] ) ) {
                continue;
            }

            foreach ( $overrides[ $key ] as $id ) {
                if ( is_string( $id ) && $id !== '' ) {
                    $disabled[ $id ] = true;
                }
            }
        }

        return $disabled;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, string>
     */
    private static function collect_renamed_labels( array $overrides ): array {
        $renamed = [];

        foreach ( [ 'renamed', 'rename' ] as $key ) {
            if ( ! isset( $overrides[ $key ] ) || ! is_array( $overrides[ $key ] ) ) {
                continue;
            }

            foreach ( $overrides[ $key ] as $id => $label ) {
                if ( is_string( $id ) && $id !== '' && is_string( $label ) && $label !== '' ) {
                    $renamed[ $id ] = $label;
                }
            }
        }

        return $renamed;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return list<mixed>
     */
    private static function collect_added_entries( array $overrides ): array {
        $added = [];

        foreach ( [ 'added', 'add' ] as $key ) {
            if ( ! isset( $overrides[ $key ] ) || ! is_array( $overrides[ $key ] ) ) {
                continue;
            }

            $added = array_merge( $added, $overrides[ $key ] );
        }

        return $added;
    }

    /**
     * @param list<mixed> $entries
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool, group: string, type: string, guidance: string, finishes: list<string>, colors: list<string>}>
     */
    private static function normalize_entries( array $entries ): array {
        $normalized = [];

        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $id    = isset( $entry['id'] ) ? (string) $entry['id'] : '';
            $label = isset( $entry['label'] ) ? (string) $entry['label'] : '';

            if ( $id === '' || $label === '' ) {
                continue;
            }

            $aliases = [];

            if ( isset( $entry['aliases'] ) && is_array( $entry['aliases'] ) ) {
                foreach ( $entry['aliases'] as $alias ) {
                    if ( is_string( $alias ) && $alias !== '' ) {
                        $aliases[] = $alias;
                    }
                }
            }

            $normalized[] = [
                'id'               => $id,
                'label'            => $label,
                'aliases'          => $aliases,
                'show_in_dropdown' => ! empty( $entry['show_in_dropdown'] ),
                'group'            => isset( $entry['group'] ) && is_string( $entry['group'] ) ? $entry['group'] : '',
                'type'             => isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : '',
                'guidance'         => isset( $entry['guidance'] ) && is_string( $entry['guidance'] ) ? $entry['guidance'] : '',
                'finishes'         => self::normalize_string_list( $entry['finishes'] ?? null ),
                'colors'           => self::normalize_string_list( $entry['colors'] ?? null ),
            ];
        }

        return $normalized;
    }
}
