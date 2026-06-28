<?php

use WpHubUpdater\Plugin\PluginInfo;
use PHPUnit\Framework\TestCase;

final class PluginInfoTest extends TestCase
{
    private function make(): PluginInfo
    {
        $info               = new PluginInfo();
        $info->name         = 'My Plugin';
        $info->slug         = 'my-plugin';
        $info->version      = '1.0.0';
        $info->homepage     = 'https://example.com';
        $info->download_url = 'https://example.com/plugin.zip';
        $info->author       = 'Jane Doe';
        $info->last_updated = '2024-01-01T00:00:00Z';
        $info->requires     = '6.0';
        $info->tested       = '6.5';
        $info->requires_php = '8.1';
        return $info;
    }

    // -------------------------------------------------------------------------
    // Base fields (via buildBaseWpObject)
    // -------------------------------------------------------------------------

    public function testToWpFormatContainsBaseFields(): void
    {
        $wp = $this->make()->toWpFormat();

        $this->assertSame('My Plugin', $wp->name);
        $this->assertSame('my-plugin', $wp->slug);
        $this->assertSame('1.0.0', $wp->version);
        $this->assertSame('https://example.com', $wp->homepage);
        $this->assertSame('https://example.com/plugin.zip', $wp->download_link);
        $this->assertSame('2024-01-01T00:00:00Z', $wp->last_updated);
        $this->assertSame('6.0', $wp->requires);
        $this->assertSame('6.5', $wp->tested);
        $this->assertSame('8.1', $wp->requires_php);
    }

    public function testToWpFormatSectionsContainsDescriptionKey(): void
    {
        $info           = $this->make();
        $info->sections = ['description' => '<p>Hello</p>', 'changelog' => '<p>Changes</p>'];
        $wp             = $info->toWpFormat();

        $this->assertArrayHasKey('description', $wp->sections);
        $this->assertArrayHasKey('changelog', $wp->sections);
    }

    public function testToWpFormatAddsEmptyDescriptionWhenNotSet(): void
    {
        $info           = $this->make();
        $info->sections = [];
        $wp             = $info->toWpFormat();

        $this->assertArrayHasKey('description', $wp->sections);
        $this->assertSame('', $wp->sections['description']);
    }

    // -------------------------------------------------------------------------
    // Author formatting
    // -------------------------------------------------------------------------

    public function testToWpFormatFormatsAuthorWithHomepage(): void
    {
        $info                  = $this->make();
        $info->author_homepage = 'https://author.example.com';
        $wp                    = $info->toWpFormat();

        $this->assertStringContainsString('https://author.example.com', $wp->author);
        $this->assertStringContainsString('Jane Doe', $wp->author);
        $this->assertStringStartsWith('<a href=', $wp->author);
    }

    public function testToWpFormatRendersPlainAuthorWhenNoHomepage(): void
    {
        $wp = $this->make()->toWpFormat();

        $this->assertSame('Jane Doe', $wp->author);
    }

    public function testToWpFormatRendersNullAuthorWhenBothNull(): void
    {
        $info                  = $this->make();
        $info->author          = null;
        $info->author_homepage = null;
        $wp                    = $info->toWpFormat();

        $this->assertNull($wp->author);
    }

    // -------------------------------------------------------------------------
    // Contributors
    // -------------------------------------------------------------------------

    public function testToWpFormatIncludesContributorsWhenSet(): void
    {
        $info               = $this->make();
        $info->contributors = [
            'janedoe' => ['display_name' => 'Jane Doe', 'profile' => 'https://profiles.wordpress.org/janedoe', 'avatar' => ''],
        ];
        $wp = $info->toWpFormat();

        $this->assertSame($info->contributors, $wp->contributors);
    }

    public function testToWpFormatOmitsContributorsWhenNull(): void
    {
        $wp = $this->make()->toWpFormat();

        $this->assertFalse(property_exists($wp, 'contributors'));
    }

    // -------------------------------------------------------------------------
    // Banners
    // -------------------------------------------------------------------------

    public function testToWpFormatFiltersBannersToHighAndLow(): void
    {
        $info          = $this->make();
        $info->banners = [
            'high'    => 'https://example.com/banner-high.png',
            'low'     => 'https://example.com/banner-low.png',
            'unknown' => 'should be removed',
        ];
        $wp = $info->toWpFormat();

        $this->assertArrayHasKey('high', $wp->banners);
        $this->assertArrayHasKey('low', $wp->banners);
        $this->assertArrayNotHasKey('unknown', $wp->banners);
    }

    public function testToWpFormatHandlesBannersWithExtraKeys(): void
    {
        $info          = $this->make();
        $info->banners = [
            'high'    => 'https://example.com/banner-high.png',
            'low'     => 'https://example.com/banner-low.png',
            'retina'  => 'should be removed',
        ];
        $wp = $info->toWpFormat();

        $this->assertArrayHasKey('high', $wp->banners);
        $this->assertArrayHasKey('low', $wp->banners);
        $this->assertArrayNotHasKey('retina', $wp->banners);
    }

    public function testToWpFormatOmitsBannersWhenEmpty(): void
    {
        $info          = $this->make();
        $info->banners = [];
        $wp            = $info->toWpFormat();

        $this->assertFalse(property_exists($wp, 'banners'));
    }

    public function testToWpFormatOmitsBannersWhenNull(): void
    {
        $wp = $this->make()->toWpFormat();

        $this->assertFalse(property_exists($wp, 'banners'));
    }

    // -------------------------------------------------------------------------
    // Icons
    // -------------------------------------------------------------------------

    public function testToWpFormatFiltersIconsToKnownKeys(): void
    {
        $info        = $this->make();
        $info->icons = [
            'svg'     => 'https://example.com/icon.svg',
            '1x'      => 'https://example.com/icon-1x.png',
            '2x'      => 'https://example.com/icon-2x.png',
            'default' => 'https://example.com/icon-default.png',
            'extra'   => 'should be removed',
        ];
        $wp = $info->toWpFormat();

        $this->assertArrayHasKey('svg', $wp->icons);
        $this->assertArrayNotHasKey('extra', $wp->icons);
    }

    public function testToWpFormatAddsDefaultIconFromFirstKnownKey(): void
    {
        $info        = $this->make();
        $info->icons = ['1x' => 'https://example.com/icon-1x.png'];
        $wp          = $info->toWpFormat();

        $this->assertSame('https://example.com/icon-1x.png', $wp->icons['default']);
    }

    public function testToWpFormatOmitsIconsPropertyWhenEmpty(): void
    {
        $info        = $this->make();
        $info->icons = [];
        $wp          = $info->toWpFormat();

        $this->assertFalse(property_exists($wp, 'icons'));
    }
}
