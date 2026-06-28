<?php

namespace WpHubUpdater\Theme;

use WpHubUpdater\AbstractAdminUI;
use WpHubUpdater\AbstractUpdater;
use WpHubUpdater\Update;

/**
 * Displays update availability notices and handles manual checks on the
 * Appearance → Themes screen.
 *
 * @internal
 */
final class ThemeAdminUI extends AbstractAdminUI
{
    private readonly \Closure $cbDisplayUpdateAvailableNotice;

    public function __construct(AbstractUpdater $updateChecker)
    {
        parent::__construct($updateChecker);
        $this->cbDisplayUpdateAvailableNotice = $this->displayUpdateAvailableNotice(...);
    }

    protected function onAdminInit(): void
    {
        if (!current_user_can("update_themes")) {
            return;
        }
        $this->handleManualCheck();
        add_action("all_admin_notices", $this->cbDisplayManualCheckResult);
        add_action("all_admin_notices", $this->cbDisplayUpdateAvailableNotice);
    }

    protected function getAdminPageUrl(): string
    {
        return self_admin_url("themes.php");
    }

    /**
     * Shows a notice on the Themes screen when a new version is available.
     * Includes an inline "Check for updates" link. Suppressed when a manual
     * check result notice is already being displayed for this theme.
     */
    private function displayUpdateAvailableNotice(): void
    {
        $currentScreen = get_current_screen();
        if (!$currentScreen || !in_array($currentScreen->id, ["themes", "themes-network"], true)) {
            return;
        }

        if (
            isset($_GET["hu_update_check_result"], $_GET["hu_slug"]) &&
            $_GET["hu_slug"] == $this->updateChecker->slug
        ) {
            return;
        }

        if (!($this->updateChecker->getUpdate() instanceof Update)) {
            return;
        }

        $title    = $this->updateChecker->getEntityTitle();
        $checkUrl = wp_nonce_url(
            add_query_arg(
                ["hu_check_for_updates" => 1, "hu_slug" => $this->updateChecker->slug],
                self_admin_url("themes.php"),
            ),
            "hu_check_for_updates",
        );

        wp_admin_notice(
            sprintf(
                "<p>%s &mdash; <a href=\"%s\">%s</a></p>",
                sprintf(
                    esc_html_x("A new version of %s is available.", "theme name", $this->updateChecker->getTextDomain()),
                    esc_html($title),
                ),
                esc_url($checkUrl),
                esc_html__("Check for updates", $this->updateChecker->getTextDomain()),
            ),
            ["type" => "warning", "dismissible" => true, "paragraph_wrap" => false],
        );
    }

    public function removeHooks(): void
    {
        remove_action("admin_init",        $this->cbOnAdminInit);
        remove_action("all_admin_notices", $this->cbDisplayManualCheckResult);
        remove_action("all_admin_notices", $this->cbDisplayUpdateAvailableNotice);
    }
}
