<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RFQ_Material_Catalog {

    /**
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
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
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
     */
    public static function get_effective_catalog(): array {
        return self::apply_overrides( self::get_default_catalog(), self::get_overrides() );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_overrides(): array {
        $overrides_raw = get_option( 'rfq_material_overrides', '' );

        if ( ! is_string( $overrides_raw ) || trim( $overrides_raw ) === '' ) {
            return [];
        }

        $overrides = json_decode( $overrides_raw, true );

        if ( ! is_array( $overrides ) || ! self::is_valid_override_shape( $overrides ) ) {
            return [];
        }

        return $overrides;
    }

    /**
     * @return list<array{
     *     id: string,
     *     label: string,
     *     default_label: string,
     *     aliases: list<string>,
     *     show_in_dropdown: bool,
     *     enabled: bool,
     *     source: string,
     *     is_renamed: bool,
     *     can_edit_aliases: bool,
     *     can_edit_dropdown: bool,
     *     can_remove: bool
     * }>
     */
    public static function get_editor_rows(): array {
        $defaults  = self::get_default_catalog();
        $overrides = self::get_overrides();
        $disabled  = self::collect_disabled_ids( $overrides );
        $renamed   = self::collect_renamed_labels( $overrides );
        $rows      = [];

        foreach ( $defaults as $entry ) {
            $id             = $entry['id'];
            $default_label  = $entry['label'];
            $label          = $renamed[ $id ] ?? $default_label;
            $rows[]         = [
                'id'                 => $id,
                'label'              => $label,
                'default_label'      => $default_label,
                'aliases'            => $entry['aliases'],
                'show_in_dropdown'   => $entry['show_in_dropdown'],
                'enabled'            => ! isset( $disabled[ $id ] ),
                'source'             => 'shipped',
                'is_renamed'         => $label !== $default_label,
                'can_edit_aliases'   => false,
                'can_edit_dropdown'  => false,
                'can_remove'         => false,
            ];
        }

        $default_ids = array_column( $defaults, 'id' );

        foreach ( self::normalize_entries( self::collect_added_entries( $overrides ) ) as $entry ) {
            if ( in_array( $entry['id'], $default_ids, true ) ) {
                continue;
            }

            $rows[] = [
                'id'                => $entry['id'],
                'label'             => $entry['label'],
                'default_label'     => $entry['label'],
                'aliases'           => $entry['aliases'],
                'show_in_dropdown'  => $entry['show_in_dropdown'],
                'enabled'           => ! isset( $disabled[ $entry['id'] ] ),
                'source'            => 'custom',
                'is_renamed'        => false,
                'can_edit_aliases'  => true,
                'can_edit_dropdown' => true,
                'can_remove'        => true,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    public static function build_overrides_from_rows( array $rows ): ?array {
        $disabled = [];
        $renamed  = [];
        $added    = [];
        $seen_ids = [];

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                return null;
            }

            $id     = isset( $row['id'] ) ? (string) $row['id'] : '';
            $label  = isset( $row['label'] ) ? trim( (string) $row['label'] ) : '';
            $source = isset( $row['source'] ) ? (string) $row['source'] : '';
            $enabled = ! empty( $row['enabled'] );

            if ( $id === '' || $label === '' ) {
                return null;
            }

            if ( isset( $seen_ids[ $id ] ) ) {
                return null;
            }

            $seen_ids[ $id ] = true;

            if ( $source === 'custom' ) {
                if ( ! self::is_valid_material_id( $id ) ) {
                    return null;
                }

                if ( ! $enabled ) {
                    $disabled[] = $id;
                }

                $aliases = [];

                if ( isset( $row['aliases'] ) && is_array( $row['aliases'] ) ) {
                    foreach ( $row['aliases'] as $alias ) {
                        if ( is_string( $alias ) && $alias !== '' ) {
                            $aliases[] = $alias;
                        }
                    }
                } elseif ( isset( $row['aliases'] ) && is_string( $row['aliases'] ) ) {
                    foreach ( explode( ',', $row['aliases'] ) as $alias ) {
                        $alias = trim( $alias );

                        if ( $alias !== '' ) {
                            $aliases[] = $alias;
                        }
                    }
                }

                $added[] = [
                    'id'               => $id,
                    'label'            => $label,
                    'aliases'          => $aliases,
                    'show_in_dropdown' => ! empty( $row['show_in_dropdown'] ),
                ];

                continue;
            }

            if ( ! $enabled ) {
                $disabled[] = $id;
            }

            $default_label = isset( $row['default_label'] ) ? (string) $row['default_label'] : $label;

            if ( $label !== $default_label ) {
                $renamed[ $id ] = $label;
            }
        }

        $overrides = [];

        if ( $disabled !== [] ) {
            $overrides['disabled'] = array_values( array_unique( $disabled ) );
        }

        if ( $renamed !== [] ) {
            $overrides['renamed'] = $renamed;
        }

        if ( $added !== [] ) {
            $overrides['added'] = $added;
        }

        return $overrides;
    }

    public static function encode_overrides( array $overrides ): string {
        $normalized = [];

        if ( isset( $overrides['disabled'] ) && is_array( $overrides['disabled'] ) && $overrides['disabled'] !== [] ) {
            $normalized['disabled'] = array_values( $overrides['disabled'] );
        }

        if ( isset( $overrides['renamed'] ) && is_array( $overrides['renamed'] ) && $overrides['renamed'] !== [] ) {
            $normalized['renamed'] = $overrides['renamed'];
        }

        if ( isset( $overrides['added'] ) && is_array( $overrides['added'] ) && $overrides['added'] !== [] ) {
            $normalized['added'] = $overrides['added'];
        }

        if ( $normalized === [] ) {
            return '';
        }

        return (string) wp_json_encode( $normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    }

    public static function is_valid_material_id( string $id ): bool {
        return (bool) preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id );
    }

    /**
     * @param list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}> $catalog
     * @param array<string, mixed> $overrides
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
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
            }
        }

        return true;
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
     * @return list<array{id: string, label: string, aliases: list<string>, show_in_dropdown: bool}>
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
            ];
        }

        return $normalized;
    }
}
