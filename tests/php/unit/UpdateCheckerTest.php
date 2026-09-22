<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[CoversClass(RFQ_Update_Checker::class)]
#[Group('AC-WP-029')]
#[Group('AC-WP-030')]
#[Group('AC-WP-031')]
class UpdateCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        remove_all_filters(RFQ_Update_Checker::STRATEGY_FILTER);
    }

    public function test_configures_exact_required_release_asset_without_authentication(): void
    {
        $api = new RFQ_Test_Update_Api();
        $checker = new RFQ_Test_Update_Checker($api);

        RFQ_Update_Checker::configure($checker);

        $this->assertSame(RFQ_Update_Checker::RELEASE_ASSET_PATTERN, $api->assetPattern);
        $this->assertSame(2, $api->assetPreference);
        $this->assertSame(RFQ_Update_Checker::STRATEGY_FILTER, $api->strategyFilter);
        $this->assertFalse($api->authenticationCalled);
        $this->assertSame([RFQ_Update_Checker::class, 'has_exact_release_asset'], $api->releaseFilter);
        $this->assertSame(1, $api->releaseTypes);
        $this->assertSame(20, $api->maxReleases);

        $this->assertSame(1, preg_match($api->assetPattern, 'rfq-intake-1.2.3.zip'));
        $this->assertSame(0, preg_match($api->assetPattern, 'wp-rfq-plugin-1.2.3.zip'));
        $this->assertSame(0, preg_match($api->assetPattern, 'rfq-intake-1.2.3-beta.1.zip'));
        $this->assertSame(0, preg_match($api->assetPattern, 'source-code.zip'));
    }

    public function test_release_tag_must_match_its_exact_package_asset(): void
    {
        $matching = (object) [
            'assets' => [(object) ['name' => 'rfq-intake-1.2.3.zip']],
        ];
        $mismatched = (object) [
            'assets' => [(object) ['name' => 'rfq-intake-1.2.4.zip']],
        ];

        $this->assertTrue(RFQ_Update_Checker::has_exact_release_asset('1.2.3', $matching));
        $this->assertFalse(RFQ_Update_Checker::has_exact_release_asset('1.2.3', $mismatched));
        $this->assertFalse(RFQ_Update_Checker::has_exact_release_asset('1.2.3-beta.1', $matching));
        $this->assertFalse(RFQ_Update_Checker::has_exact_release_asset('1.2.3', (object) []));
    }

    public function test_detection_strategy_cannot_fall_back_to_tag_or_branch(): void
    {
        $release = static fn (): string => 'release';
        $strategies = RFQ_Update_Checker::only_stable_releases(
            [
                'latest_release' => $release,
                'latest_tag' => static fn (): string => 'tag',
                'branch' => static fn (): string => 'branch',
            ]
        );

        $this->assertSame(['latest_release' => $release], $strategies);
        $this->assertSame([], RFQ_Update_Checker::only_stable_releases(['latest_tag' => static fn (): string => 'tag']));
    }

    public function test_disables_only_this_plugins_automatic_updates(): void
    {
        $thisPlugin = (object) ['plugin' => 'rfq-intake/rfq-intake.php'];
        $otherPlugin = (object) ['plugin' => 'other/other.php'];

        $this->assertFalse(RFQ_Update_Checker::disable_automatic_updates(true, $thisPlugin));
        $this->assertTrue(RFQ_Update_Checker::disable_automatic_updates(true, $otherPlugin));
        $this->assertNull(RFQ_Update_Checker::disable_automatic_updates(null, $otherPlugin));
    }

    public function test_replaces_only_this_plugins_auto_update_control(): void
    {
        $html = RFQ_Update_Checker::render_manual_update_setting(
            '<a>Enable auto-updates</a>',
            'rfq-intake/rfq-intake.php',
            []
        );

        $this->assertStringContainsString('Manual updates only', $html);
        $this->assertSame(
            '<a>Enable auto-updates</a>',
            RFQ_Update_Checker::render_manual_update_setting(
                '<a>Enable auto-updates</a>',
                'other/other.php',
                []
            )
        );
    }
}

final class RFQ_Test_Update_Checker
{
    public function __construct(private RFQ_Test_Update_Api $api)
    {
    }

    public function getVcsApi(): RFQ_Test_Update_Api
    {
        return $this->api;
    }
}

final class RFQ_Test_Update_Api
{
    public ?string $assetPattern = null;

    public ?int $assetPreference = null;

    public ?string $strategyFilter = null;

    public bool $authenticationCalled = false;

    public mixed $releaseFilter = null;

    public ?int $releaseTypes = null;

    public ?int $maxReleases = null;

    public function enableReleaseAssets(string $pattern, int $preference): void
    {
        $this->assetPattern = $pattern;
        $this->assetPreference = $preference;
    }

    public function setStrategyFilterName(string $filter): void
    {
        $this->strategyFilter = $filter;
    }

    public function setReleaseFilter(callable $filter, int $releaseTypes, int $maxReleases): void
    {
        $this->releaseFilter = $filter;
        $this->releaseTypes = $releaseTypes;
        $this->maxReleases = $maxReleases;
    }

    public function setAuthentication(string $token): void
    {
        unset($token);
        $this->authenticationCalled = true;
    }
}
