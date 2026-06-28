<?php

use WpHubUpdater\Update;
use PHPUnit\Framework\TestCase;

final class UpdateTest extends TestCase
{
    protected function setUp(): void
    {
        wp_test_reset();
    }

    // -------------------------------------------------------------------------
    // restore()
    // -------------------------------------------------------------------------

    public function testRestoreCopiesAllKnownFields(): void
    {
        $obj               = new stdClass();
        $obj->slug         = 'my-plugin';
        $obj->version      = '2.0.0';
        $obj->download_url = 'https://example.com/plugin.zip';
        $obj->homepage     = 'https://example.com';
        $obj->icons        = ['1x' => 'https://example.com/icon.png'];
        $obj->filename     = 'my-plugin/my-plugin.php';
        $obj->last_updated = '2024-06-01T00:00:00Z';
        $obj->requires     = '6.0';
        $obj->requires_php = '8.1';

        $update = Update::restore($obj);

        $this->assertSame('my-plugin', $update->slug);
        $this->assertSame('2.0.0', $update->version);
        $this->assertSame('https://example.com/plugin.zip', $update->download_url);
        $this->assertSame('https://example.com', $update->homepage);
        $this->assertSame(['1x' => 'https://example.com/icon.png'], $update->icons);
        $this->assertSame('my-plugin/my-plugin.php', $update->filename);
        $this->assertSame('2024-06-01T00:00:00Z', $update->last_updated);
        $this->assertSame('6.0', $update->requires);
        $this->assertSame('8.1', $update->requires_php);
    }

    public function testRestoreIgnoresUnknownFields(): void
    {
        $obj              = new stdClass();
        $obj->slug        = 'my-plugin';
        $obj->unknown_key = 'should be ignored';

        $update = Update::restore($obj);

        $this->assertSame('my-plugin', $update->slug);
        $this->assertFalse(property_exists($update, 'unknown_key'));
    }

    public function testRestoreDoesNotCallApplyFilters(): void
    {
        $obj       = new stdClass();
        $obj->slug = 'my-plugin';

        Update::restore($obj);

        $this->assertSame([], $GLOBALS['_wp_applied_filters']);
    }

    public function testRestoreWithPartialFields(): void
    {
        $obj          = new stdClass();
        $obj->slug    = 'partial';
        $obj->version = '1.0';

        $update = Update::restore($obj);

        $this->assertSame('partial', $update->slug);
        $this->assertSame('1.0', $update->version);
        $this->assertNull($update->download_url);
        $this->assertNull($update->homepage);
        $this->assertSame([], $update->icons);
    }

    // -------------------------------------------------------------------------
    // fromObject()
    // -------------------------------------------------------------------------

    public function testFromObjectCallsApplyFiltersWithSlugInTagName(): void
    {
        $obj       = new stdClass();
        $obj->slug = 'test-plugin';

        Update::fromObject($obj);

        $tags = array_column($GLOBALS['_wp_applied_filters'], 'tag');
        $this->assertContains('hu_retain_fields-test-plugin', $tags);
    }

    public function testFromObjectWithFilterThatRemovesField(): void
    {
        $obj          = new stdClass();
        $obj->slug    = 'filtered-plugin';
        $obj->version = '1.5.0';

        // Override the filter to drop 'version' from the field list.
        $GLOBALS['_wp_filter_overrides']['hu_retain_fields-filtered-plugin'] =
            fn(array $fields) => array_values(array_diff($fields, ['version']));

        $update = Update::fromObject($obj);

        $this->assertSame('filtered-plugin', $update->slug);
        $this->assertNull($update->version);
    }

    // -------------------------------------------------------------------------
    // toStdClass()
    // -------------------------------------------------------------------------

    public function testToStdClassContainsAllFieldNames(): void
    {
        $update               = new Update();
        $update->slug         = 'my-plugin';
        $update->version      = '1.0.0';
        $update->download_url = 'https://example.com/z.zip';
        $update->homepage     = 'https://example.com';
        $update->filename     = 'my-plugin/my-plugin.php';
        $update->last_updated = '2024-01-01T00:00:00Z';
        $update->requires     = '6.0';
        $update->requires_php = '8.1';

        $obj = $update->toStdClass();

        foreach (['slug', 'version', 'download_url', 'homepage', 'icons', 'filename', 'last_updated', 'requires', 'requires_php'] as $field) {
            $this->assertTrue(property_exists($obj, $field), "Expected field: $field");
        }
    }

    public function testToStdClassRoundtripsViaRestore(): void
    {
        $update               = new Update();
        $update->slug         = 'roundtrip';
        $update->version      = '3.0.0';
        $update->download_url = 'https://example.com/z.zip';
        $update->icons        = ['svg' => 'https://example.com/icon.svg'];

        $restored = Update::restore($update->toStdClass());

        $this->assertSame($update->slug, $restored->slug);
        $this->assertSame($update->version, $restored->version);
        $this->assertSame($update->download_url, $restored->download_url);
        $this->assertSame($update->icons, $restored->icons);
    }

    // -------------------------------------------------------------------------
    // toWpFormat()
    // -------------------------------------------------------------------------

    public function testToWpFormatMapsFieldsToWpShape(): void
    {
        $update               = new Update();
        $update->slug         = 'my-plugin';
        $update->version      = '2.1.0';
        $update->download_url = 'https://example.com/plugin.zip';
        $update->homepage     = 'https://example.com';
        $update->filename     = 'my-plugin/my-plugin.php';

        $wp = $update->toWpFormat();

        $this->assertSame('my-plugin', $wp->slug);
        $this->assertSame('2.1.0', $wp->new_version);
        $this->assertSame('https://example.com/plugin.zip', $wp->package);
        $this->assertSame('https://example.com', $wp->url);
        $this->assertSame('my-plugin/my-plugin.php', $wp->plugin);
    }

    public function testToWpFormatFiltersIconsToKnownKeys(): void
    {
        $update        = new Update();
        $update->icons = [
            'svg'     => 'https://example.com/icon.svg',
            '1x'      => 'https://example.com/icon-1x.png',
            '2x'      => 'https://example.com/icon-2x.png',
            'default' => 'https://example.com/icon-default.png',
            'unknown' => 'should be removed',
        ];

        $wp = $update->toWpFormat();

        $this->assertArrayHasKey('svg', $wp->icons);
        $this->assertArrayHasKey('1x', $wp->icons);
        $this->assertArrayHasKey('2x', $wp->icons);
        $this->assertArrayHasKey('default', $wp->icons);
        $this->assertArrayNotHasKey('unknown', $wp->icons);
    }

    public function testToWpFormatSetsDefaultIconFromFirstKnownKey(): void
    {
        $update        = new Update();
        $update->icons = ['1x' => 'https://example.com/icon-1x.png'];

        $wp = $update->toWpFormat();

        $this->assertSame('https://example.com/icon-1x.png', $wp->icons['default']);
    }

    public function testToWpFormatDoesNotOverrideExistingDefaultIcon(): void
    {
        $update        = new Update();
        $update->icons = [
            '1x'      => 'https://example.com/icon-1x.png',
            'default' => 'https://example.com/my-default.png',
        ];

        $wp = $update->toWpFormat();

        $this->assertSame('https://example.com/my-default.png', $wp->icons['default']);
    }

    public function testToWpFormatDoesNotAddIconsPropertyWhenEmpty(): void
    {
        $update        = new Update();
        $update->icons = [];

        $wp = $update->toWpFormat();

        $this->assertFalse(property_exists($wp, 'icons'));
    }

    public function testToWpFormatDoesNotAddIconsWhenAllKeysFiltered(): void
    {
        $update        = new Update();
        $update->icons = ['unknown' => 'https://example.com/x.png'];

        $wp = $update->toWpFormat();

        $this->assertFalse(property_exists($wp, 'icons'));
    }
}
