<?php

use WpHubUpdater\Theme\ThemeInfo;
use PHPUnit\Framework\TestCase;

final class ThemeInfoTest extends TestCase
{
    private function make(): ThemeInfo
    {
        $info               = new ThemeInfo();
        $info->name         = 'My Theme';
        $info->slug         = 'my-theme';
        $info->version      = '2.0.0';
        $info->homepage     = 'https://example.com';
        $info->download_url = 'https://example.com/theme.zip';
        $info->author       = 'John Smith';
        $info->last_updated = '2024-03-01T00:00:00Z';
        $info->requires     = '6.2';
        $info->tested       = '6.5';
        $info->requires_php = '8.2';
        return $info;
    }

    public function testToWpFormatContainsBaseFields(): void
    {
        $wp = $this->make()->toWpFormat();

        $this->assertSame('My Theme', $wp->name);
        $this->assertSame('my-theme', $wp->slug);
        $this->assertSame('2.0.0', $wp->version);
        $this->assertSame('https://example.com', $wp->homepage);
        $this->assertSame('https://example.com/theme.zip', $wp->download_link);
        $this->assertSame('2024-03-01T00:00:00Z', $wp->last_updated);
        $this->assertSame('6.2', $wp->requires);
        $this->assertSame('6.5', $wp->tested);
        $this->assertSame('8.2', $wp->requires_php);
    }

    public function testToWpFormatIncludesScreenshotUrl(): void
    {
        $info                 = $this->make();
        $info->screenshot_url = 'https://example.com/screenshot.png';
        $wp                   = $info->toWpFormat();

        $this->assertSame('https://example.com/screenshot.png', $wp->screenshot_url);
    }

    public function testToWpFormatOmitsScreenshotUrlWhenNull(): void
    {
        $wp = $this->make()->toWpFormat();

        $this->assertFalse(property_exists($wp, 'screenshot_url'));
    }

    public function testToWpFormatFormatsAuthorWithHomepage(): void
    {
        $info                  = $this->make();
        $info->author_homepage = 'https://author.example.com';
        $wp                    = $info->toWpFormat();

        $this->assertStringContainsString('https://author.example.com', $wp->author);
        $this->assertStringContainsString('John Smith', $wp->author);
    }

    public function testToWpFormatRendersPlainAuthorWhenNoHomepage(): void
    {
        $wp = $this->make()->toWpFormat();

        $this->assertSame('John Smith', $wp->author);
    }

    public function testToWpFormatSectionsHasDescriptionKey(): void
    {
        $info           = $this->make();
        $info->sections = ['description' => '<p>A theme</p>'];
        $wp             = $info->toWpFormat();

        $this->assertArrayHasKey('description', $wp->sections);
        $this->assertSame('<p>A theme</p>', $wp->sections['description']);
    }

    public function testToWpFormatNullFields(): void
    {
        $info = new ThemeInfo();
        $wp   = $info->toWpFormat();

        $this->assertNull($wp->name);
        $this->assertNull($wp->slug);
        $this->assertNull($wp->version);
        $this->assertNull($wp->requires);
        $this->assertNull($wp->tested);
        $this->assertNull($wp->requires_php);
        $this->assertNull($wp->download_link);
    }
}
