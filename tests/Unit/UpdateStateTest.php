<?php

use WpHubUpdater\Update;
use WpHubUpdater\UpdateState;
use PHPUnit\Framework\TestCase;

final class UpdateStateTest extends TestCase
{
    protected function setUp(): void
    {
        wp_test_reset();
    }

    // -------------------------------------------------------------------------
    // Constructor / hydration
    // -------------------------------------------------------------------------

    public function testFreshStateHasZeroValues(): void
    {
        $state = new UpdateState('test_option');

        $this->assertSame(0, $state->getLastCheck());
        $this->assertNull($state->getUpdate());
    }

    public function testConstructorHydratesLastCheckAndVersion(): void
    {
        $stored                 = new stdClass();
        $stored->lastCheck      = 1_700_000_000;
        $stored->checkedVersion = '1.5.0';
        $GLOBALS['_wp_test_options']['test_option'] = $stored;

        $state = new UpdateState('test_option');

        $this->assertSame(1_700_000_000, $state->getLastCheck());
    }

    public function testConstructorHydratesStoredUpdate(): void
    {
        $updateObj               = new stdClass();
        $updateObj->slug         = 'my-plugin';
        $updateObj->version      = '2.0.0';
        $updateObj->download_url = 'https://example.com/plugin.zip';

        $stored                 = new stdClass();
        $stored->lastCheck      = 1_700_000_000;
        $stored->checkedVersion = '1.0.0';
        $stored->update         = $updateObj;
        $GLOBALS['_wp_test_options']['test_option'] = $stored;

        $state  = new UpdateState('test_option');
        $update = $state->getUpdate();

        $this->assertInstanceOf(Update::class, $update);
        $this->assertSame('my-plugin', $update->slug);
        $this->assertSame('2.0.0', $update->version);
    }

    public function testConstructorWithNonObjectOptionIsNoOp(): void
    {
        $GLOBALS['_wp_test_options']['test_option'] = 'not-an-object';

        $state = new UpdateState('test_option');

        $this->assertSame(0, $state->getLastCheck());
        $this->assertNull($state->getUpdate());
    }

    // -------------------------------------------------------------------------
    // Timing
    // -------------------------------------------------------------------------

    public function testTimeSinceLastCheckWithZeroIsLarge(): void
    {
        $state = new UpdateState('test_option');

        $this->assertGreaterThan(1_000_000_000, $state->timeSinceLastCheck());
    }

    public function testSetLastCheckToNowResultsInNearZeroTimeSince(): void
    {
        $state = new UpdateState('test_option');
        $state->setLastCheckToNow();

        $this->assertLessThanOrEqual(2, $state->timeSinceLastCheck());
    }

    public function testGetLastCheckReturnsStoredTimestamp(): void
    {
        $state = new UpdateState('test_option');
        $state->setLastCheckToNow();

        $this->assertEqualsWithDelta(time(), $state->getLastCheck(), 2);
    }

    // -------------------------------------------------------------------------
    // Update getter / setter
    // -------------------------------------------------------------------------

    public function testSetAndGetUpdate(): void
    {
        $state  = new UpdateState('test_option');
        $update = new Update();
        $update->slug    = 'my-plugin';
        $update->version = '3.0.0';

        $state->setUpdate($update);

        $this->assertSame($update, $state->getUpdate());
    }

    public function testSetUpdateToNullClearsUpdate(): void
    {
        $stored                 = new stdClass();
        $stored->lastCheck      = 0;
        $stored->checkedVersion = '';
        $updateObj              = new stdClass();
        $updateObj->slug        = 'my-plugin';
        $updateObj->version     = '1.0';
        $stored->update         = $updateObj;
        $GLOBALS['_wp_test_options']['test_option'] = $stored;

        $state = new UpdateState('test_option');
        $state->setUpdate(null);

        $this->assertNull($state->getUpdate());
    }

    public function testSetCheckedVersionFluent(): void
    {
        $state  = new UpdateState('test_option');
        $result = $state->setCheckedVersion('2.5.0');

        $this->assertSame($state, $result);
    }

    // -------------------------------------------------------------------------
    // save() / persist
    // -------------------------------------------------------------------------

    public function testSavePersistsLastCheckAndVersion(): void
    {
        $state = new UpdateState('test_option');
        $state->setLastCheckToNow()->setCheckedVersion('1.2.3')->save();

        $stored = $GLOBALS['_wp_test_options']['test_option'];

        $this->assertIsObject($stored);
        $this->assertEqualsWithDelta(time(), $stored->lastCheck, 2);
        $this->assertSame('1.2.3', $stored->checkedVersion);
    }

    public function testSavePersistsUpdate(): void
    {
        $state  = new UpdateState('test_option');
        $update = new Update();
        $update->slug    = 'my-plugin';
        $update->version = '4.0.0';

        $state->setUpdate($update)->save();

        $stored = $GLOBALS['_wp_test_options']['test_option'];
        $this->assertSame('my-plugin', $stored->update->slug);
        $this->assertSame('4.0.0', $stored->update->version);
    }

    public function testSaveWithNoUpdateDoesNotWriteUpdateKey(): void
    {
        $state = new UpdateState('test_option');
        $state->save();

        $stored = $GLOBALS['_wp_test_options']['test_option'];
        $this->assertFalse(isset($stored->update));
    }

    // -------------------------------------------------------------------------
    // reset()
    // -------------------------------------------------------------------------

    public function testResetZerosAllFieldsAndPersists(): void
    {
        $state  = new UpdateState('test_option');
        $update = new Update();
        $update->slug = 'my-plugin';

        $state->setLastCheckToNow()->setCheckedVersion('1.0')->setUpdate($update)->reset();

        $this->assertSame(0, $state->getLastCheck());
        $this->assertNull($state->getUpdate());

        $stored = $GLOBALS['_wp_test_options']['test_option'];
        $this->assertSame(0, $stored->lastCheck);
        $this->assertSame('', $stored->checkedVersion);
        $this->assertFalse(isset($stored->update));
    }
}
