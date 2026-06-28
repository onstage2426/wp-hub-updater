<?php

namespace WpHubUpdater\Theme;

use WpHubUpdater\AbstractAdminUI;
use WpHubUpdater\AbstractInfo;
use WpHubUpdater\AbstractUpdater;
use WpHubUpdater\GitHubClient;
use WpHubUpdater\Update;

/**
 * Delivers WordPress theme updates from a GitHub repository.
 *
 * Usage:
 *   ThemeUpdater::build('https://github.com/org/my-theme', 'my-theme')
 *       ->setAccessToken('ghp_...');
 */
final class ThemeUpdater extends AbstractUpdater
{
    private function __construct(
        GitHubClient $api,
        /** The theme's directory name, identical to the theme slug. */
        public readonly string $directoryName,
        int $checkPeriod = 12,
        string $optionName = "",
    ) {
        parent::__construct($api, $this->directoryName, $checkPeriod, $optionName);
    }

    /** Creates and wires up a new theme updater for the given GitHub repository. */
    public static function build(
        string $repositoryUrl,
        string $themeSlug,
        int $checkPeriod = 12,
        string $optionName = "",
    ): static {
        return new self(new GitHubClient($repositoryUrl), $themeSlug, $checkPeriod, $optionName);
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: identity & metadata
    // -------------------------------------------------------------------------

    public function getEntityType(): string
    {
        return "theme";
    }

    public function getDirectoryName(): string
    {
        return $this->directoryName;
    }

    public function getEntityTitle(): string
    {
        $theme = wp_get_theme($this->slug);
        if ($theme->exists()) {
            $name = $theme->get("Name");
            if ($name !== false && $name !== "") {
                return $name;
            }
        }
        return $this->slug;
    }

    protected function fetchInstalledVersion(): ?string
    {
        $theme = wp_get_theme($this->slug);
        if (!$theme->exists()) {
            $this->triggerError(
                sprintf("Cannot find theme '%s'. Is the slug correct?", $this->slug),
                E_USER_WARNING,
            );
            return null;
        }

        $version = $theme->get("Version");
        if ($version === false || $version === "") {
            $this->triggerError(
                sprintf("Cannot read the Version header for theme '%s'.", $this->slug),
                E_USER_WARNING,
            );
            return null;
        }

        return $version;
    }

    protected function getLocalDirectoryPath(): string
    {
        return get_theme_root($this->slug) . DIRECTORY_SEPARATOR . $this->slug;
    }

    protected function getSchedulerHooks(): array
    {
        return ["load-themes.php"];
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: info building
    // -------------------------------------------------------------------------

    protected function createInfoObject(): AbstractInfo
    {
        return new ThemeInfo();
    }

    protected function populateInfoFromLocal(AbstractInfo $info): void
    {
        /** @var ThemeInfo $info */
        $theme = wp_get_theme($this->slug);
        if (!$theme->exists()) {
            return;
        }

        $info->name            = $theme->get("Name") ?: null;
        $info->homepage        = $theme->get("ThemeURI") ?: null;
        $info->author          = $theme->get("Author") ?: null;
        $info->author_homepage = $theme->get("AuthorURI") ?: null;

        $description = $theme->get("Description");
        if ($description) {
            $info->sections["description"] = $description;
        }

        $screenshot = $theme->get_screenshot();
        if ($screenshot !== false) {
            $info->screenshot_url = $screenshot;
        }
    }

    #[\Override]
    protected function getMetadataFilename(): string
    {
        return 'hu.json';
    }

    protected function applyTypeSpecificMetadata(AbstractInfo $info, object $metadata): void
    {
        /** @var ThemeInfo $info */
        if ($info->screenshot_url === null && isset($metadata->screenshot_url) && is_string($metadata->screenshot_url)) {
            $info->screenshot_url = $metadata->screenshot_url;
        }
    }

    protected function matchesInfoRequest(?string $action, mixed $args): bool
    {
        return $action === "theme_information" && isset($args->slug) && $args->slug === $this->slug;
    }

    protected function getInfoFilterName(): string
    {
        return "themes_api";
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: update transient injection
    // -------------------------------------------------------------------------

    /** @return array<string, mixed> */
    protected function prepareUpdateEntry(Update $update): array
    {
        return [
            "theme"        => $this->slug,
            "new_version"  => $update->version,
            "url"          => $update->homepage ?? "",
            "package"      => $update->download_url,
            "requires"     => $update->requires,
            "requires_php" => $update->requires_php,
        ];
    }

    /** @return array<string, mixed> */
    protected function buildNoUpdateEntry(?string $currentVersion): array
    {
        return [
            "theme"        => $this->slug,
            "new_version"  => $currentVersion ?? "",
            "url"          => "",
            "package"      => "",
            "requires"     => null,
            "requires_php" => null,
        ];
    }

    protected function getUpdateTransientName(): string
    {
        return "site_transient_update_themes";
    }

    protected function getUpdateListKey(): string
    {
        return $this->slug;
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: WP.org exclusion
    // -------------------------------------------------------------------------

    protected function getWpOrgApiPath(): string
    {
        return "/themes/update-check/1.";
    }

    protected function getWpOrgBodyKey(): string
    {
        return "themes";
    }

    protected function removeFromWpOrgPayload(array $payload): array
    {
        unset($payload["themes"][$this->slug]);
        return $payload;
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: upgrader tracking
    // -------------------------------------------------------------------------

    protected function getUpgraderHookExtraKey(): string
    {
        return "theme";
    }

    protected function getUpgraderEntityId(): string
    {
        return $this->slug;
    }

    protected function getEntitySourceDirectory(): string
    {
        return get_theme_root($this->getDirectoryName());
    }

    protected function extractEntityFromSkin(\WP_Upgrader $upgrader): ?string
    {
        $skin = $upgrader->skin;
        if ($skin instanceof \Theme_Upgrader_Skin && $skin->theme !== "") {
            return $skin->theme;
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: lifecycle hooks
    // -------------------------------------------------------------------------

    protected function installTypeHooks(): void {}

    protected function removeTypeHooks(): void {}

    protected function createAdminUI(): AbstractAdminUI
    {
        return new ThemeAdminUI($this);
    }
}
