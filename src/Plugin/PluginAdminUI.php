<?php

namespace WpHubUpdater\Plugin;

use WpHubUpdater\AbstractAdminUI;
use WpHubUpdater\Update;

/**
 * Adds "View details" and "Check for updates" links to the plugin row, handles
 * the manual check request, and displays result notices.
 *
 * @internal
 */
final class PluginAdminUI extends AbstractAdminUI
{
    private readonly PluginUpdater $pluginUpdater;
    private readonly \Closure $cbAddViewDetailsLink;
    private readonly \Closure $cbAddCheckForUpdatesLink;

    public function __construct(PluginUpdater $updateChecker)
    {
        parent::__construct($updateChecker);
        $this->pluginUpdater          = $updateChecker;
        $this->cbAddViewDetailsLink   = $this->addViewDetailsLink(...);
        $this->cbAddCheckForUpdatesLink = $this->addCheckForUpdatesLink(...);
    }

    protected function onAdminInit(): void
    {
        if (!current_user_can("update_plugins")) {
            return;
        }
        $this->handleManualCheck();
        add_filter("plugin_row_meta", $this->cbAddViewDetailsLink,     10, 3);
        add_filter("plugin_row_meta", $this->cbAddCheckForUpdatesLink, 10, 2);
        add_action("all_admin_notices", $this->cbDisplayManualCheckResult);
    }

    protected function getAdminPageUrl(): string
    {
        return self_admin_url("plugins.php");
    }

    // -------------------------------------------------------------------------
    // Plugin row links
    // -------------------------------------------------------------------------

    /**
     * @param array<int, string> $pluginMeta
     * @param array<string, mixed> $pluginData
     * @return array<int, string>
     */
    private function addViewDetailsLink(
        array $pluginMeta,
        string $pluginFile,
        array $pluginData = [],
    ): array {
        if (
            !$this->isMyPluginFile($pluginFile) ||
            isset($pluginData["slug"])
        ) {
            return $pluginMeta;
        }

        $linkText = apply_filters(
            $this->updateChecker->getUniqueName("view_details_link"),
            __("View details"),
        );
        if (empty($linkText)) {
            return $pluginMeta;
        }

        $position           = "append";
        $visitPluginSiteIdx = count($pluginMeta) - 1;

        $pluginUri  = $pluginData["PluginURI"] ?? '';
        $pluginName = $pluginData["Name"] ?? '';

        if ($pluginUri) {
            $escapedUri = esc_url($pluginUri);
            foreach ($pluginMeta as $idx => $link) {
                if (str_contains((string) $link, $escapedUri)) {
                    $visitPluginSiteIdx = $idx;
                    $position = apply_filters(
                        $this->updateChecker->getUniqueName("view_details_link_position"),
                        "before",
                    );
                    break;
                }
            }
        }

        $viewDetailsLink = sprintf(
            '<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
            esc_url(network_admin_url(
                "plugin-install.php?tab=plugin-information&plugin=" .
                    urlencode($this->pluginUpdater->slug) .
                    "&TB_iframe=true&width=600&height=550",
            )),
            esc_attr(sprintf(__("More information about %s"), $pluginName)),
            esc_attr($pluginName),
            $linkText,
        );

        match ($position) {
            "before"  => array_splice($pluginMeta, $visitPluginSiteIdx, 0, $viewDetailsLink),
            "after"   => array_splice($pluginMeta, $visitPluginSiteIdx + 1, 0, $viewDetailsLink),
            "replace" => ($pluginMeta[$visitPluginSiteIdx] = $viewDetailsLink),
            default   => ($pluginMeta[] = $viewDetailsLink),
        };

        return $pluginMeta;
    }

    /**
     * @param array<int, string> $pluginMeta
     * @return array<int, string>
     */
    private function addCheckForUpdatesLink(array $pluginMeta, string $pluginFile): array
    {
        if (!$this->isMyPluginFile($pluginFile)) {
            return $pluginMeta;
        }

        $linkUrl  = wp_nonce_url(
            add_query_arg(
                ["hu_check_for_updates" => 1, "hu_slug" => $this->pluginUpdater->slug],
                self_admin_url("plugins.php"),
            ),
            "hu_check_for_updates",
        );
        $linkText = apply_filters(
            $this->updateChecker->getUniqueName("manual_check_link"),
            __("Check for updates", $this->updateChecker->getTextDomain()),
        );

        if (!empty($linkText)) {
            $pluginMeta[] = sprintf('<a href="%s">%s</a>', esc_url($linkUrl), $linkText);
        }

        return $pluginMeta;
    }

    private function isMyPluginFile(string $pluginFile): bool
    {
        return $pluginFile === $this->pluginUpdater->pluginFile ||
            ($this->pluginUpdater->getMuPluginFile() !== "" &&
                $pluginFile === $this->pluginUpdater->getMuPluginFile());
    }

    public function removeHooks(): void
    {
        remove_action("admin_init",        $this->cbOnAdminInit);
        remove_filter("plugin_row_meta",   $this->cbAddViewDetailsLink,     10);
        remove_filter("plugin_row_meta",   $this->cbAddCheckForUpdatesLink, 10);
        remove_action("all_admin_notices", $this->cbDisplayManualCheckResult);
    }
}
