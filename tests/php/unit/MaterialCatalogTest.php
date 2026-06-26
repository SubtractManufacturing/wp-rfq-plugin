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
}
