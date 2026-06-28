<?php

use WpHubUpdater\Reference;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase
{
    public function testConstructorStoresAllFields(): void
    {
        $ref = new Reference(
            name:          'v1.2.3',
            downloadUrl:   'https://github.com/org/repo/archive/v1.2.3.zip',
            version:       '1.2.3',
            updated:       '2024-01-15T10:00:00Z',
            changelog:     '<p>Some changes</p>',
            downloadCount: 42,
        );

        $this->assertSame('v1.2.3', $ref->name);
        $this->assertSame('https://github.com/org/repo/archive/v1.2.3.zip', $ref->downloadUrl);
        $this->assertSame('1.2.3', $ref->version);
        $this->assertSame('2024-01-15T10:00:00Z', $ref->updated);
        $this->assertSame('<p>Some changes</p>', $ref->changelog);
        $this->assertSame(42, $ref->downloadCount);
    }

    public function testNullableFieldsAcceptNull(): void
    {
        $ref = new Reference(
            name:          'main',
            downloadUrl:   'https://api.github.com/repos/org/repo/zipball/HEAD',
            version:       null,
            updated:       null,
            changelog:     null,
            downloadCount: null,
        );

        $this->assertNull($ref->version);
        $this->assertNull($ref->updated);
        $this->assertNull($ref->changelog);
        $this->assertNull($ref->downloadCount);
    }

    public function testFieldsAreReadonly(): void
    {
        $ref = new Reference('v1.0.0', 'https://example.com/zip', '1.0.0', null, null, null);

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line
        $ref->name = 'mutated';
    }
}
