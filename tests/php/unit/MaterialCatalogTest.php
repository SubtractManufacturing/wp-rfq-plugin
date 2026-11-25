<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Material_Catalog::class)]
#[Group('AC-WP-028')]
#[Group('AC-WP-034')]
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
        $this->assertContains('6061-T6 Aluminum', $labels);
        $this->assertContains('7075-T6 Aluminum', $labels);
        $this->assertContains('304 Stainless Steel', $labels);
    }

    public function test_default_entries_include_aliases_and_dropdown_flags(): void
    {
        $catalog = RFQ_Material_Catalog::get_default_catalog();
        $by_id = [];

        foreach ($catalog as $entry) {
            $by_id[$entry['id']] = $entry;
        }

        $this->assertSame(['1018', '1018 steel', 'mild steel', 'cold rolled steel'], $by_id['1018-steel']['aliases']);
        $this->assertTrue($by_id['6061-t6-aluminum']['show_in_dropdown']);
    }

    public function test_default_entries_carry_the_material_tree(): void
    {
        $catalog = RFQ_Material_Catalog::get_default_catalog();
        $by_id = [];

        foreach ($catalog as $entry) {
            $by_id[$entry['id']] = $entry;
        }

        $this->assertSame('Non-Ferrous', $by_id['6061-t6-aluminum']['group']);
        $this->assertSame('Aluminum', $by_id['6061-t6-aluminum']['type']);
        $this->assertContains('Anodize Type II', $by_id['6061-t6-aluminum']['finishes']);
        $this->assertSame([], $by_id['6061-t6-aluminum']['colors']);
        $this->assertNotSame('', $by_id['6061-t6-aluminum']['guidance']);
        $this->assertSame(1, $by_id['6061-t6-aluminum']['material_cost']);
        $this->assertSame(1, $by_id['6061-t6-aluminum']['machining_cost']);

        $this->assertSame('Plastics', $by_id['abs']['group']);
        $this->assertContains('Black', $by_id['abs']['colors']);

        $this->assertSame('Exotics', $by_id['inconel-718']['group']);
        $this->assertSame('Nickel alloys', $by_id['inconel-718']['type']);
    }

    public function test_cost_ratings_outside_one_to_five_are_rejected_or_dropped(): void
    {
        $this->assertFalse(RFQ_Material_Catalog::is_valid_override_shape([
            'added' => [['id' => 'x', 'label' => 'X', 'material_cost' => 6]],
        ]));
        $this->assertTrue(RFQ_Material_Catalog::is_valid_override_shape([
            'added' => [['id' => 'x', 'label' => 'X', 'material_cost' => 3, 'machining_cost' => 5]],
        ]));

        update_option(
            'rfq_material_overrides',
            json_encode(['added' => [['id' => 'monel-400', 'label' => 'Monel 400', 'machining_cost' => 0]]], JSON_THROW_ON_ERROR)
        );

        $by_id = [];
        foreach (RFQ_Material_Catalog::get_effective_catalog() as $entry) {
            $by_id[$entry['id']] = $entry;
        }

        $this->assertNull($by_id['monel-400']['material_cost']);
        $this->assertNull($by_id['monel-400']['machining_cost']);
    }

    public function test_effective_catalog_merges_disable_rename_and_add_overrides(): void
    {
        update_option(
            'rfq_material_overrides',
            json_encode([
                'disabled' => ['1018-steel'],
                'renamed' => ['6061-t6-aluminum' => '6061 Aluminum'],
                'added' => [
                    [
                        'id' => 'monel-400',
                        'label' => 'Monel 400',
                        'aliases' => ['monel', '400'],
                        'show_in_dropdown' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $catalog = RFQ_Material_Catalog::get_effective_catalog();
        $labels = array_column($catalog, 'label');
        $ids = array_column($catalog, 'id');

        $this->assertNotContains('1018 Steel', $labels);
        $this->assertContains('6061 Aluminum', $labels);
        $this->assertContains('monel-400', $ids);
        $this->assertContains('Monel 400', $labels);
    }

    public function test_invalid_override_json_returns_defaults_only(): void
    {
        update_option('rfq_material_overrides', '{not-json');

        $catalog = RFQ_Material_Catalog::get_effective_catalog();

        $this->assertNotEmpty($catalog);
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
}
