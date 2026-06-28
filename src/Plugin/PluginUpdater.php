<?php

namespace WpHubUpdater\Plugin;

use WpHubUpdater\AbstractAdminUI;
use WpHubUpdater\AbstractInfo;
use WpHubUpdater\AbstractUpdater;
use WpHubUpdater\GitHubClient;
use WpHubUpdater\Update;
use stdClass;

/**
 * Delivers WordPress plugin updates from a GitHub repository.
 *
 * Usage:
 *   PluginUpdater::build('https://github.com/org/repo', __FILE__)
 *       ->setAccessToken('ghp_...');
 */
final class PluginUpdater extends AbstractUpdater
{
    /** WordPress-relative plugin path, e.g. `my-plugin/my-plugin.php`. */
    public readonly string $pluginFile;
    /** The plugin's directory name within wp-content/plugins. */
    public readonly string $directoryName;
    private string $muPluginFile = "";

    private readonly string $pluginAbsolutePath;
    private ?bool $cachedMuPlugin = null;
    private readonly \Closure $cbRemoveUpdaterCron;
    private readonly \Closure $cbRemoveHooks;

    private function __construct(
        GitHubClient $api,
        string $pluginAbsolutePath,
        string $slug = "",
        int $checkPeriod = 12,
        string $optionName = "",
    ) {
        $this->pluginAbsolutePath = self::normalizePath($pluginAbsolutePath);
        $this->pluginFile         = plugin_basename($this->pluginAbsolutePath);
        $pluginDir                = dirname($this->pluginFile);

        if ($slug === "") {
            $slug = basename($this->pluginFile, ".php");
        }

        $this->directoryName      = ($pluginDir === '.') ? $slug : $pluginDir;
        $this->cbRemoveUpdaterCron = $this->removeUpdaterCron(...);
        $this->cbRemoveHooks       = $this->removeHooks(...);

        if (strpbrk($this->pluginFile, "/\\") === false && $this->isMuPlugin()) {
            $this->muPluginFile = $this->pluginFile;
        }

        parent::__construct($api, $slug, $checkPeriod, $optionName);
    }

    // -------------------------------------------------------------------------
    // Public API (plugin-specific)
    // -------------------------------------------------------------------------

    /**
     * Explicitly identifies the MU-plugin file when it cannot be auto-detected.
     * Required for MU-plugins that live inside a subdirectory.
     */
    public function setMuPluginFile(string $muPluginFile): static
    {
        $this->muPluginFile = $muPluginFile;
        return $this;
    }

    /** @internal */
    public function getMuPluginFile(): string
    {
        return $this->muPluginFile;
    }

    /** Creates and wires up a new plugin updater for the given GitHub repository. */
    public static function build(
        string $repositoryUrl,
        string $pluginFile,
        string $slug = "",
        int $checkPeriod = 12,
        string $optionName = "",
    ): static {
        return new self(
            new GitHubClient($repositoryUrl),
            self::normalizePath($pluginFile),
            $slug,
            $checkPeriod,
            $optionName,
        );
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: identity & metadata
    // -------------------------------------------------------------------------

    public function getEntityType(): string
    {
        return "plugin";
    }

    public function getDirectoryName(): string
    {
        return $this->directoryName;
    }

    public function getEntityTitle(): string
    {
        $header = $this->getPluginHeader();
        $name   = $header["Name"] ?? "";
        $domain = $header["TextDomain"] ?? "";
        if (is_string($name) && $name !== "" && is_string($domain)) {
            return translate($name, $domain);
        }
        return $this->slug;
    }

    protected function fetchInstalledVersion(): ?string
    {
        $header  = $this->getPluginHeader();
        $version = $header["Version"] ?? null;
        if (is_string($version) && $version !== "") {
            return $version;
        }

        $this->triggerError(
            sprintf(
                "Cannot read the Version header for '%s'. The filename is incorrect or is not a plugin.",
                $this->pluginFile,
            ),
            E_USER_WARNING,
        );
        return null;
    }

    protected function getLocalDirectoryPath(): string
    {
        return dirname($this->pluginAbsolutePath);
    }

    protected function getSchedulerHooks(): array
    {
        return ["load-plugins.php"];
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: info building
    // -------------------------------------------------------------------------

    protected function createInfoObject(): AbstractInfo
    {
        return new PluginInfo();
    }

    protected function populateInfoFromLocal(AbstractInfo $info): void
    {
        /** @var PluginInfo $info */
        $header = $this->getPluginHeader();

        foreach ([
            "Version"    => "version",
            "Name"       => "name",
            "PluginURI"  => "homepage",
            "Author"     => "author",
            "AuthorName" => "author",
            "AuthorURI"  => "author_homepage",
        ] as $headerName => $property) {
            $value = $header[$headerName] ?? null;
            if (is_string($value) && $value !== "") {
                $info->$property = $value;
            }
        }

        $description = $header["Description"] ?? null;
        if (is_string($description) && $description !== "") {
            $info->sections["description"] = $description;
        }

        $icons = $this->getLocalAssetUrls([
            "icon.svg"         => "svg",
            "icon-256x256.png" => "2x",
            "icon-256x256.jpg" => "2x",
            "icon-128x128.png" => "1x",
            "icon-128x128.jpg" => "1x",
        ]);
        if ($icons !== []) {
            $icons["default"] = reset($icons);
            $info->icons = $icons;
        }
    }

    protected function populateInfoExtended(AbstractInfo $info): void
    {
        /** @var PluginInfo $info */
        $banners = $this->getLocalAssetUrls([
            "banner-772x250.png"  => "low",
            "banner-772x250.jpg"  => "low",
            "banner-1544x500.png" => "high",
            "banner-1544x500.jpg" => "high",
        ]);
        if ($banners !== []) {
            $info->banners = $banners;
        }
    }

    #[\Override]
    protected function getMetadataFilename(): string
    {
        return 'hu.json';
    }

    protected function applyTypeSpecificMetadata(AbstractInfo $info, object $metadata): void
    {
        /** @var PluginInfo $info */
        if ($info->banners === [] && isset($metadata->banners) && is_object($metadata->banners)) {
            $info->banners = get_object_vars($metadata->banners);
        }

        if ($info->icons === [] && isset($metadata->icons) && is_object($metadata->icons)) {
            $icons = array_filter(
                (array) $metadata->icons,
                is_string(...),
            );
            if ($icons !== []) {
                $icons["default"] ??= current($icons);
                $info->icons = $icons;
            }
        }
    }

    protected function matchesInfoRequest(?string $action, mixed $args): bool
    {
        return
            $action === "plugin_information" &&
            isset($args->slug) &&
            ($args->slug === $this->slug || $args->slug === $this->directoryName);
    }

    protected function getInfoFilterName(): string
    {
        return "plugins_api";
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: update transient injection
    // -------------------------------------------------------------------------

    protected function prepareUpdateEntry(Update $update): object
    {
        $entry = $update->toWpFormat();
        if ($this->isMuPlugin()) {
            $entry->package = null;
        }
        return $entry;
    }

    protected function buildNoUpdateEntry(?string $currentVersion): object
    {
        return (object) [
            "new_version"   => $currentVersion,
            "url"           => "",
            "package"       => "",
            "requires_php"  => "",
            "id"            => $this->pluginFile,
            "slug"          => $this->slug,
            "plugin"        => $this->pluginFile,
            "icons"         => [],
            "banners"       => [],
            "banners_rtl"   => [],
            "tested"        => "",
            "compatibility" => new stdClass(),
        ];
    }

    protected function getUpdateTransientName(): string
    {
        return "site_transient_update_plugins";
    }

    protected function getUpdateListKey(): string
    {
        return $this->isMuPlugin() ? $this->muPluginFile : $this->pluginFile;
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: WP.org exclusion
    // -------------------------------------------------------------------------

    protected function getWpOrgApiPath(): string
    {
        return "/plugins/update-check/1.";
    }

    protected function getWpOrgBodyKey(): string
    {
        return "plugins";
    }

    protected function removeFromWpOrgPayload(array $payload): array
    {
        $updateListKey = $this->getUpdateListKey();
        unset($payload["plugins"][$updateListKey]);

        if (!empty($payload["active"]) && is_array($payload["active"])) {
            $payload["active"] = array_values(
                array_filter($payload["active"], fn($path) => $path !== $updateListKey),
            );
        }

        return $payload;
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: upgrader tracking
    // -------------------------------------------------------------------------

    protected function getUpgraderHookExtraKey(): string
    {
        return "plugin";
    }

    protected function getUpgraderEntityId(): string
    {
        return $this->pluginFile;
    }

    protected function getEntitySourceDirectory(): string
    {
        return WP_PLUGIN_DIR;
    }

    protected function extractEntityFromSkin(\WP_Upgrader $upgrader): ?string
    {
        $skin = $upgrader->skin;
        if ($skin instanceof \Plugin_Upgrader_Skin && $skin->plugin !== "") {
            return $skin->plugin;
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: lifecycle hooks
    // -------------------------------------------------------------------------

    protected function installTypeHooks(): void
    {
        register_deactivation_hook($this->pluginAbsolutePath, $this->cbRemoveUpdaterCron);
        add_action("uninstall_" . $this->pluginFile, $this->cbRemoveHooks);
    }

    protected function removeTypeHooks(): void
    {
        remove_action("deactivate_" . $this->pluginFile, $this->cbRemoveUpdaterCron);
        remove_action("uninstall_" . $this->pluginFile,  $this->cbRemoveHooks);
    }

    protected function createAdminUI(): AbstractAdminUI
    {
        return new PluginAdminUI($this);
    }

    // -------------------------------------------------------------------------
    // AbstractUpdater: optional overrides
    // -------------------------------------------------------------------------

    #[\Override]
    protected function shouldInjectUpdate(): bool
    {
        return !$this->isUnknownMuPlugin();
    }

    #[\Override]
    protected function shouldExcludeFromWpOrgChecks(): bool
    {
        $updateUri = $this->getPluginHeader()["UpdateURI"] ?? "";
        if ($updateUri === "" || $updateUri === false) {
            return true;
        }
        $host = wp_parse_url((string) $updateUri, PHP_URL_HOST);
        return !is_string($host) || str_ends_with(strtolower($host), "wordpress.org");
    }

    #[\Override]
    protected function decorateUpdate(Update $update): Update
    {
        $update->filename = $this->pluginFile;
        return $update;
    }

    // -------------------------------------------------------------------------
    // Plugin-specific helpers
    // -------------------------------------------------------------------------

    private function isMuPlugin(): bool
    {
        if ($this->cachedMuPlugin !== null) {
            return $this->cachedMuPlugin;
        }

        if (!defined("WPMU_PLUGIN_DIR")) {
            return $this->cachedMuPlugin = false;
        }

        $muPluginDir = realpath(WPMU_PLUGIN_DIR);
        $pluginPath  = realpath($this->pluginAbsolutePath);

        if ($muPluginDir === false || $pluginPath === false) {
            $muPluginDir = self::normalizePath(WPMU_PLUGIN_DIR);
            $pluginPath  = self::normalizePath($this->pluginAbsolutePath);
        }

        return $this->cachedMuPlugin = str_starts_with($pluginPath, $muPluginDir);
    }

    private function isUnknownMuPlugin(): bool
    {
        return $this->muPluginFile === "" && $this->isMuPlugin();
    }

    /** @return array<string, string|bool> */
    private function getPluginHeader(): array
    {
        if (!is_file($this->pluginAbsolutePath)) {
            $this->triggerError(
                sprintf("Can't read the plugin header for '%s'. The file does not exist.", $this->pluginFile),
                E_USER_WARNING,
            );
            return [];
        }

        if (!function_exists("get_plugin_data")) {
            require_once ABSPATH . "/wp-admin/includes/plugin.php";
        }
        return get_plugin_data($this->pluginAbsolutePath, false, false);
    }

    /**
     * @param array<string,string> $filesToKeys filename → asset key
     * @return array<string,string> asset key → public URL
     */
    private function getLocalAssetUrls(array $filesToKeys): array
    {
        $assetDirectory = $this->getLocalDirectoryPath() . DIRECTORY_SEPARATOR . "assets";
        if (!is_dir($assetDirectory)) {
            return [];
        }

        $assetBaseUrl = trailingslashit(plugins_url("", $assetDirectory . "/imaginary.file"));
        $foundAssets  = [];

        foreach ($filesToKeys as $fileName => $key) {
            if (!isset($foundAssets[$key]) && is_file($assetDirectory . DIRECTORY_SEPARATOR . $fileName)) {
                $foundAssets[$key] = $assetBaseUrl . $fileName;
            }
        }

        return $foundAssets;
    }

    private static function normalizePath(string $path): string
    {
        if (function_exists("wp_normalize_path")) {
            return wp_normalize_path($path);
        }
        $path = str_replace("\\", "/", $path);
        $path = preg_replace("|(?<=.)/+|", "/", $path);
        if (substr((string) $path, 1, 1) === ":") {
            return ucfirst((string) $path);
        }
        return $path;
    }
}
