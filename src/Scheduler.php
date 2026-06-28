<?php

namespace WpHubUpdater;

/**
 * Manages when update checks run.
 *
 * Registers a WP-Cron event and hooks into relevant admin page loads to
 * trigger periodic checks. Applies throttling to avoid redundant API calls
 * when an update is already known to be pending.
 *
 * @internal
 */
final class Scheduler
{
    private bool $throttleRedundantChecks = false;
    private int $throttledCheckPeriod = 72;

    /** @var string[] */
    private array $hourlyCheckHooks = ["load-update.php"];
    private ?string $cronHook = null;

    private readonly \Closure $cbMaybeCheckForUpdates;
    private readonly \Closure $cbAddCustomSchedule;
    private readonly \Closure $cbRemoveHooksIfLibraryGone;
    private readonly \Closure $cbUpgraderProcessComplete;

    /**
     * Registers the cron event, admin hooks, and optional WP-CLI hooks.
     * Cancels any existing cron job when the check period is zero.
     *
     * @param string[] $hourlyHooks Additional admin page hooks that trigger an
     *                              hourly-cadence check.
     */
    public function __construct(
        private readonly AbstractUpdater $updateChecker,
        private readonly int $checkPeriod,
        array $hourlyHooks = ["load-plugins.php"],
    ) {
        $this->cronHook = $this->updateChecker->getUniqueName("cron_check_updates");

        $this->cbMaybeCheckForUpdates     = $this->maybeCheckForUpdates(...);
        $this->cbAddCustomSchedule        = $this->addCustomSchedule(...);
        $this->cbRemoveHooksIfLibraryGone = $this->removeHooksIfLibraryGone(...);
        $this->cbUpgraderProcessComplete  = $this->upgraderProcessComplete(...);

        if ($this->checkPeriod > 0) {
            $scheduleName = match (true) {
                $this->checkPeriod === 1  => "hourly",
                $this->checkPeriod === 12 => "twicedaily",
                $this->checkPeriod === 24 => "daily",
                default                   => "hu_every" . $this->checkPeriod . "hours",
            };

            if ($scheduleName === "hu_every" . $this->checkPeriod . "hours") {
                add_filter("cron_schedules", $this->cbAddCustomSchedule);
            }

            if (!wp_next_scheduled($this->cronHook) && !defined("WP_INSTALLING")) {
                $upperLimit = max($this->checkPeriod * 3600 - 15 * 60, 1);
                $randomOffset = function_exists("wp_rand")
                    ? wp_rand(0, $upperLimit)
                    : random_int(0, $upperLimit);
                $firstCheckTime = apply_filters(
                    $this->updateChecker->getUniqueName("first_check_time"),
                    time() - $randomOffset,
                );
                wp_schedule_event($firstCheckTime, $scheduleName, $this->cronHook);
            }

            add_action($this->cronHook,         $this->cbMaybeCheckForUpdates);
            add_action("admin_init",            $this->cbMaybeCheckForUpdates);
            add_action("load-update-core.php",  $this->cbMaybeCheckForUpdates);

            $this->hourlyCheckHooks = array_merge($this->hourlyCheckHooks, $hourlyHooks);
            foreach ($this->hourlyCheckHooks as $hook) {
                add_action($hook, $this->cbMaybeCheckForUpdates);
            }

            add_action("upgrader_process_complete", $this->cbRemoveHooksIfLibraryGone, 1, 0);
            add_action("upgrader_process_complete", $this->cbUpgraderProcessComplete, 11, 2);
        } else {
            wp_clear_scheduled_hook($this->cronHook);
        }

        if (defined("WP_CLI") && class_exists(\WP_CLI::class, false)) {
            $triggered = false;
            $trigger = function (mixed $input = null) use (&$triggered): mixed {
                if (!$triggered) {
                    $triggered = true;
                    $this->maybeCheckForUpdates();
                }
                return $input;
            };
            $entityType = $this->updateChecker->getEntityType();
            foreach (["{$entityType} status", "{$entityType} list", "{$entityType} update"] as $command) {
                \WP_CLI::add_hook("before_invoke:" . $command, $trigger);
            }
        }
    }

    /**
     * Configures whether and how long to extend the check interval when an
     * update is already pending.
     */
    public function setThrottle(bool $enable, int $hours): void
    {
        $this->throttleRedundantChecks = $enable;
        $this->throttledCheckPeriod    = $hours;
    }

    /**
     * Fires a check if sufficient time has elapsed since the last one,
     * according to the effective interval for the current hook context.
     */
    private function maybeCheckForUpdates(): void
    {
        if ($this->checkPeriod === 0) {
            return;
        }

        $lastCheck   = $this->updateChecker->getLastCheck();
        $shouldCheck = (time() - $lastCheck) >= $this->getEffectiveCheckPeriod();

        $shouldCheck = apply_filters(
            $this->updateChecker->getUniqueName("check_now"),
            $shouldCheck,
            $lastCheck,
            $this->checkPeriod,
        );

        if ((bool) $shouldCheck) {
            $this->updateChecker->checkForUpdates();
        }
    }

    /**
     * Returns the minimum interval in seconds that must pass before the next
     * check is allowed. The value is adjusted for the current hook context:
     * the update core screen and post-upgrade hooks use a short interval,
     * hourly hooks enforce an hourly cadence, and throttling extends the
     * interval when an update is already pending.
     */
    private function getEffectiveCheckPeriod(): int
    {
        $currentFilter = current_filter();

        if (in_array($currentFilter, ["load-update-core.php", "upgrader_process_complete"])) {
            return 60;
        }
        if (in_array($currentFilter, $this->hourlyCheckHooks)) {
            return 3600;
        }
        if ($this->throttleRedundantChecks && $this->updateChecker->getUpdate() instanceof Update) {
            return $this->throttledCheckPeriod * 3600;
        }
        if (defined("DOING_CRON") && DOING_CRON) {
            return $this->checkPeriod * 3600 - 20 * 60;
        }
        return $this->checkPeriod * 3600;
    }

    /**
     * Tears down all hooks when the library file no longer exists on disk.
     * Prevents stale cron callbacks from running after the plugin is deleted.
     */
    private function removeHooksIfLibraryGone(): void
    {
        clearstatcache();
        if (!file_exists(__FILE__)) {
            $this->removeHooks();
            $this->updateChecker->removeHooks();
        }
    }

    /**
     * Triggers a re-check immediately after this plugin is upgraded, so the
     * stored state reflects the newly installed version.
     *
     * @param array<string, mixed> $upgradeInfo
     */
    private function upgraderProcessComplete(\WP_Upgrader $upgrader, array $upgradeInfo): void
    {
        $type = $this->updateChecker->getEntityType();

        if (
            !isset($upgradeInfo["type"], $upgradeInfo["action"]) ||
            $upgradeInfo["action"] !== "update" ||
            $upgradeInfo["type"] !== $type
        ) {
            return;
        }

        $directoryName = strtolower($this->updateChecker->getDirectoryName());

        if ($type === "theme") {
            if (
                !isset($upgradeInfo["themes"]) ||
                !in_array($directoryName, array_map(strtolower(...), (array) $upgradeInfo["themes"]))
            ) {
                return;
            }
        } else {
            if (!isset($upgradeInfo["plugins"])) {
                return;
            }
            if (!in_array(
                $directoryName,
                array_map(dirname(...), array_map(strtolower(...), $upgradeInfo["plugins"])),
            )) {
                return;
            }
        }

        $this->maybeCheckForUpdates();
    }

    /**
     * Registers a custom WP-Cron interval when the configured check period
     * does not map to one of WP's built-in schedule names.
     *
     * @param array<string, array{interval: int, display: string}> $schedules
     * @return array<string, array{interval: int, display: string}>
     */
    private function addCustomSchedule(array $schedules): array
    {
        $scheduleName = "hu_every" . $this->checkPeriod . "hours";
        $schedules[$scheduleName] = [
            "interval" => $this->checkPeriod * 3600,
            "display"  => sprintf("Every %d hours", $this->checkPeriod),
        ];
        return $schedules;
    }

    /** Cancels the scheduled cron event. Intended to be called on plugin deactivation. */
    public function removeUpdaterCron(): void
    {
        wp_clear_scheduled_hook($this->cronHook);
    }

    /** Removes all registered action and filter hooks, and cancels the scheduled cron event. */
    public function removeHooks(): void
    {
        remove_filter("cron_schedules",         $this->cbAddCustomSchedule);
        remove_action("admin_init",             $this->cbMaybeCheckForUpdates);
        remove_action("load-update-core.php",   $this->cbMaybeCheckForUpdates);
        remove_action("upgrader_process_complete", $this->cbRemoveHooksIfLibraryGone, 1);
        remove_action("upgrader_process_complete", $this->cbUpgraderProcessComplete,  11);

        if ($this->cronHook !== null) {
            remove_action($this->cronHook, $this->cbMaybeCheckForUpdates);
            wp_clear_scheduled_hook($this->cronHook);
        }
        foreach ($this->hourlyCheckHooks as $hook) {
            remove_action($hook, $this->cbMaybeCheckForUpdates);
        }
    }
}
