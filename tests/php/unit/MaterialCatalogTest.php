<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Material_Catalog::class)]
#[Group('AC-WP-028')]
class MaterialCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['rfq_test_options'] = [];
    }

    public function test_default_catalog_includes_required_materials(): void
    {
        $catalog = RFQ_Material_Catalog::get_default_catalog();
        $labels = array_column($catalog, 'label');

        $this->assertContains('1018 Steel', $labels);
        $this->assertContains('6061 Aluminum', $labels);
        $this->assertContains('7075 Aluminum', $labels);
        $this->assertContains('304 Stainless', $labels);
    }

    public function test_default_entries_include_aliases_and_dropdown_flags(): void
    {
        $catalog = RFQ_Material_Catalog::get_default_catalog();
        $by_id = [];

        foreach ($catalog as $entry) {
            $by_id[$entry['id']] = $entry;
        }

        $this->assertSame(['1018', '1018 steel'], $by_id['1018-steel']['aliases']);
        $this->assertTrue($by_id['6061-aluminum']['show_in_dropdown']);
    }

    public function test_effective_catalog_merges_disable_rename_and_add_overrides(): void
    {
        update_option(
            'rfq_material_overrides',
            json_encode([
                'disabled' => ['1018-steel'],
                'renamed' => ['6061-aluminum' => '6061-T6 Aluminum'],
                'added' => [
                    [
                        'id' => 'grade-5-titanium',
                        'label' => 'Grade 5 Titanium',
                        'aliases' => ['ti-6al-4v'],
                        'show_in_dropdown' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $catalog = RFQ_Material_Catalog::get_effective_catalog();
        $labels = array_column($catalog, 'label');
        $ids = array_column($catalog, 'id');

        $this->assertNotContains('1018 Steel', $labels);
        $this->assertContains('6061-T6 Aluminum', $labels);
        $this->assertContains('grade-5-titanium', $ids);
        $this->assertContains('Grade 5 Titanium', $labels);
    }

    public function test_invalid_override_json_returns_defaults_only(): void
    {
        update_option('rfq_material_overrides', '{not-json');

        $catalog = RFQ_Material_Catalog::get_effective_catalog();

        $this->assertCount(4, $catalog);
        $this->assertSame('1018 Steel', $catalog[0]['label']);
    }

    public function test_added_entry_with_existing_id_is_skipped(): void
    {
        update_option(
            'rfq_material_overrides',
            json_encode([
                'added' => [
                    [
                        'id' => '1018-steel',
                        'label' => 'Duplicate Steel',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $catalog = RFQ_Material_Catalog::get_effective_catalog();
        $labels = array_column($catalog, 'label');

        $this->assertContains('1018 Steel', $labels);
        $this->assertNotContains('Duplicate Steel', $labels);
    }

    public function test_is_valid_override_shape_rejects_wrong_types(): void
    {
        $this->assertTrue(RFQ_Material_Catalog::is_valid_override_shape([]));
        $this->assertFalse(RFQ_Material_Catalog::is_valid_override_shape(['disabled' => 'not-array']));
        $this->assertFalse(RFQ_Material_Catalog::is_valid_override_shape(['renamed' => true]));
        $this->assertFalse(RFQ_Material_Catalog::is_valid_override_shape(['added' => [['id' => '', 'label' => 'X']]]));
    }

    public function test_get_editor_rows_reflects_disable_rename_and_add_overrides(): void
    {
        update_option(
            'rfq_material_overrides',
            json_encode([
                'disabled' => ['1018-steel'],
                'renamed' => ['6061-aluminum' => '6061-T6 Aluminum'],
                'added' => [
                    [
                        'id' => 'grade-5-titanium',
                        'label' => 'Grade 5 Titanium',
                        'aliases' => ['ti-6al-4v'],
                        'show_in_dropdown' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $rows = RFQ_Material_Catalog::get_editor_rows();
        $by_id = [];

        foreach ($rows as $row) {
            $by_id[$row['id']] = $row;
        }

        $this->assertFalse($by_id['1018-steel']['enabled']);
        $this->assertSame('6061-T6 Aluminum', $by_id['6061-aluminum']['label']);
        $this->assertTrue($by_id['6061-aluminum']['is_renamed']);
        $this->assertFalse($by_id['6061-aluminum']['can_edit_aliases']);
        $this->assertSame('custom', $by_id['grade-5-titanium']['source']);
        $this->assertTrue($by_id['grade-5-titanium']['can_edit_aliases']);
        $this->assertSame(['ti-6al-4v'], $by_id['grade-5-titanium']['aliases']);
    }

    public function test_build_overrides_from_rows_round_trips_with_editor_rows(): void
    {
        update_option(
            'rfq_material_overrides',
            json_encode([
                'disabled' => ['7075-aluminum'],
                'renamed' => ['304-stainless' => '304 SS'],
                'added' => [
                    [
                        'id' => 'brass-360',
                        'label' => '360 Brass',
                        'aliases' => ['c360'],
                        'show_in_dropdown' => true,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $rows = RFQ_Material_Catalog::get_editor_rows();
        $overrides = RFQ_Material_Catalog::build_overrides_from_rows($rows);

        $this->assertNotNull($overrides);
        $this->assertSame(['7075-aluminum'], $overrides['disabled']);
        $this->assertSame(['304-stainless' => '304 SS'], $overrides['renamed']);
        $this->assertCount(1, $overrides['added']);
        $this->assertSame('brass-360', $overrides['added'][0]['id']);
    }

    public function test_build_overrides_from_rows_rejects_invalid_custom_id(): void
    {
        $rows = RFQ_Material_Catalog::get_editor_rows();
        $rows[] = [
            'id' => 'Invalid ID',
            'label' => 'Bad Material',
            'default_label' => 'Bad Material',
            'aliases' => [],
            'show_in_dropdown' => true,
            'enabled' => true,
            'source' => 'custom',
            'is_renamed' => false,
            'can_edit_aliases' => true,
            'can_edit_dropdown' => true,
            'can_remove' => true,
        ];

        $this->assertNull(RFQ_Material_Catalog::build_overrides_from_rows($rows));
    }

    public function test_build_overrides_from_rows_rejects_duplicate_ids(): void
    {
        $rows = RFQ_Material_Catalog::get_editor_rows();
        $rows[] = $rows[0];

        $this->assertNull(RFQ_Material_Catalog::build_overrides_from_rows($rows));
    }

    public function test_encode_overrides_returns_empty_string_for_no_changes(): void
    {
        $this->assertSame('', RFQ_Material_Catalog::encode_overrides([]));
    }

    public function test_is_valid_material_id_accepts_slug_format(): void
    {
        $this->assertTrue(RFQ_Material_Catalog::is_valid_material_id('grade-5-titanium'));
        $this->assertFalse(RFQ_Material_Catalog::is_valid_material_id('Invalid ID'));
        $this->assertFalse(RFQ_Material_Catalog::is_valid_material_id(''));
    }
}
