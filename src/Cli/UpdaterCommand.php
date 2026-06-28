<?php

namespace WpHubUpdater\Cli;

use WpHubUpdater\AbstractUpdater;

/**
 * WP-CLI command group for a single updater instance.
 * Registered as `wp hu <slug>` when WP_CLI is defined.
 *
 * @internal
 */
final readonly class UpdaterCommand
{
    public function __construct(private AbstractUpdater $updater) {}

    /**
     * Check GitHub for a new version and update the stored state.
     *
     * ## EXAMPLES
     *
     *   wp hu my-plugin check
     *
     * @when after_wp_load
     */
    public function check(): void
    {
        \WP_CLI::log('Checking for updates...');
        $update = $this->updater->checkForUpdates();
        foreach ($this->updater->getLastRequestApiErrors() as $err) {
            \WP_CLI::warning(sprintf(
                '[%s] %s',
                $err['error']->get_error_code(),
                $err['error']->get_error_message(),
            ));
        }
        if ($update instanceof \WpHubUpdater\Update) {
            \WP_CLI::success(sprintf('Update available: %s', $update->version));
        } else {
            \WP_CLI::success('Already at the latest version.');
        }
    }

    /**
     * Display the current update state: installed version, last check, and available version.
     *
     * ## EXAMPLES
     *
     *   wp hu my-plugin status
     *
     * @when after_wp_load
     */
    public function status(): void
    {
        foreach ($this->updater->getStatus() as $key => $value) {
            \WP_CLI::log(sprintf('%-22s %s', str_replace('_', ' ', $key) . ':', $value));
        }
    }

    /**
     * Clear the stored update state and reset the last-check timestamp.
     *
     * ## EXAMPLES
     *
     *   wp hu my-plugin clear
     *
     * @when after_wp_load
     */
    public function clear(): void
    {
        $this->updater->resetState();
        \WP_CLI::success('Update state cleared.');
    }

    /**
     * Opt this entity in to WordPress automatic background updates.
     *
     * ## EXAMPLES
     *
     *   wp hu my-plugin enable-auto-updates
     *
     * @when after_wp_load
     */
    public function enable_auto_updates(): void
    {
        $this->updater->enableAutoUpdates(true);
        \WP_CLI::success('Automatic updates enabled.');
    }

    /**
     * Opt this entity out of WordPress automatic background updates.
     *
     * ## EXAMPLES
     *
     *   wp hu my-plugin disable-auto-updates
     *
     * @when after_wp_load
     */
    public function disable_auto_updates(): void
    {
        $this->updater->enableAutoUpdates(false);
        \WP_CLI::success('Automatic updates disabled.');
    }
}
