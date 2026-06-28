<?php

namespace WpHubUpdater;

/**
 * Persists the update check state between requests.
 *
 * Stores the timestamp of the last check, the installed version at that
 * time, and any pending update. Backed by a WP site option so state
 * survives across page loads and is shared across multisite subsites.
 *
 * @internal
 */
final class UpdateState
{
    private int $lastCheck = 0;
    private string $checkedVersion = '';
    private ?Update $update = null;

    /**
     * Loads persisted state from the database. If no state exists yet the
     * instance starts with sensible zero values.
     */
    public function __construct(private readonly string $optionName)
    {
        $state = get_site_option($optionName, null);
        if (!is_object($state)) {
            return;
        }
        $this->lastCheck      = intval($state->lastCheck ?? 0);
        $this->checkedVersion = $state->checkedVersion ?? '';
        if (isset($state->update)) {
            $this->update = Update::restore($state->update);
        }
    }

    /** Returns the number of seconds elapsed since the last check. */
    public function timeSinceLastCheck(): int
    {
        return time() - $this->lastCheck;
    }

    /** Returns the Unix timestamp of the last check, or zero if never checked. */
    public function getLastCheck(): int
    {
        return $this->lastCheck;
    }

    /** Records the current time as the last-check timestamp. */
    public function setLastCheckToNow(): static
    {
        $this->lastCheck = time();
        return $this;
    }

    /** Returns the stored update, or null if no update was found on the last check. */
    public function getUpdate(): ?Update
    {
        return $this->update;
    }

    /** Stores a pending update, or clears it when passed null. */
    public function setUpdate(?Update $update = null): static
    {
        $this->update = $update;
        return $this;
    }

    /** Records the installed plugin version at the time of the last check. */
    public function setCheckedVersion(string $version): static
    {
        $this->checkedVersion = $version;
        return $this;
    }

    /** Resets all state fields to zero values and persists the result. */
    public function reset(): void
    {
        $this->lastCheck      = 0;
        $this->checkedVersion = '';
        $this->update         = null;
        $this->save();
    }

    /** Writes the current state to the database. */
    public function save(): void
    {
        $state                 = new \stdClass();
        $state->lastCheck      = $this->lastCheck;
        $state->checkedVersion = $this->checkedVersion;

        if ($this->update instanceof Update) {
            $state->update = $this->update->toStdClass();
        }

        $this->persistOption($this->optionName, $state);
    }

    /**
     * Persists a site option with autoload disabled on single-site installs.
     * State options are only needed on specific admin screens, not every page.
     */
    private function persistOption(string $name, mixed $value): void
    {
        if (is_multisite()) {
            update_site_option($name, $value);
        } else {
            update_option($name, $value, autoload: false);
        }
    }
}
