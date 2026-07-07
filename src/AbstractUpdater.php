<?php

namespace WpHubUpdater;

use stdClass;
use WP_Error;

/**
 * Orchestrates the full WordPress update pipeline for a single GitHub-hosted
 * entity (plugin or theme).
 *
 * All shared logic lives here. Concrete subclasses implement the abstract
 * methods that describe type-specific behaviour.
 *
 * @internal
 */
abstract class AbstractUpdater
{
    const int RELEASE_FILTER_ALL             = GitHubClient::RELEASE_FILTER_ALL;
    const int RELEASE_FILTER_SKIP_PRERELEASE = GitHubClient::RELEASE_FILTER_SKIP_PRERELEASE;

    const string STRATEGY_LATEST_RELEASE = GitHubClient::STRATEGY_LATEST_RELEASE;
    const string STRATEGY_LATEST_TAG     = GitHubClient::STRATEGY_LATEST_TAG;
    const string STRATEGY_BRANCH         = GitHubClient::STRATEGY_BRANCH;
    private readonly UpdateState $updateState;
    private string $branch = "main";
    private ?bool $debugMode = null;
    private int $cooldownHours = 24;
    private readonly Scheduler $scheduler;
    private readonly AbstractAdminUI $extraUi;

    private ?string $cachedInstalledVersion = null;
    private ?string $upgradingEntity = null;

    /** @var array<int,array{error:\WP_Error,httpResponse:mixed,url:string|null}> */
    private array $lastRequestApiErrors = [];

    private bool $metadataHostResolved = false;
    private string|null $cachedMetadataHost = null;
    private string|null $cachedRepositoryPath = null;
    private ?string $accessToken = null;
    private string $textDomain = '';

    /** @var array<string,string> Shared registry across all updater instances. */
    private static array $registeredSlugs = [];

    private readonly \Closure $cbClearCachedVersion;
    private readonly \Closure $cbSetUpgradedThing;
    private readonly \Closure $cbSetUpgradedFromOptions;
    private readonly \Closure $cbClearUpgradedThing;
    private readonly \Closure $cbInjectInfo;
    private readonly \Closure $cbInjectUpdate;
    private readonly \Closure $cbFixDirectoryName;
    private readonly \Closure $cbInjectRollbackData;
    private readonly \Closure $cbAllowMetadataHost;
    private readonly \Closure $cbExcludeEntityFromWordPressAPI;
    private readonly \Closure $cbInjectAuthorizationHeader;
    private readonly \Closure $cbCollectApiErrors;

    // =========================================================================
    // Abstract: identity & metadata
    // =========================================================================

    /** Returns the entity type string: 'plugin' or 'theme'. */
    abstract public function getEntityType(): string;

    /** Returns the directory name of the entity as it appears on disk. */
    abstract public function getDirectoryName(): string;

    /** Returns the human-readable name for admin notices. */
    abstract public function getEntityTitle(): string;

    /** Reads the installed version directly from its source (no caching). */
    abstract protected function fetchInstalledVersion(): ?string;

    /** Returns the absolute path to the entity's directory on disk. Used for changelog lookup. */
    abstract protected function getLocalDirectoryPath(): string;

    /** @return list<string> Additional WP admin page hooks on which to trigger an hourly check. */
    abstract protected function getSchedulerHooks(): array;

    // =========================================================================
    // Abstract: info building
    // =========================================================================

    abstract protected function createInfoObject(): AbstractInfo;

    /** Populates info from local filesystem (plugin header or wp_get_theme). */
    abstract protected function populateInfoFromLocal(AbstractInfo $info): void;

    /** Populates extra info only needed for the "View details" popup. */
    protected function populateInfoExtended(AbstractInfo $info): void {}

    /**
     * Returns the filename of the optional metadata JSON file in the repo root
     * ('hu.json'). Return an empty string to skip metadata fetching entirely.
     */
    protected function getMetadataFilename(): string
    {
        return '';
    }

    /**
     * Applies type-specific fields from the parsed metadata object (e.g. banners
     * and icons for plugins, screenshot_url for themes). Called after the shared
     * fields have already been applied.
     */
    protected function applyTypeSpecificMetadata(AbstractInfo $info, object $metadata): void {}

    /** Returns true when the WordPress info API request targets this entity. */
    abstract protected function matchesInfoRequest(?string $action, mixed $args): bool;

    /** Returns the WP filter name for the info API ('plugins_api' or 'themes_api'). */
    abstract protected function getInfoFilterName(): string;

    // =========================================================================
    // Abstract: update transient injection
    // =========================================================================

    /**
     * Returns the WP object to write into the update transient response array.
     * Responsible for any type-specific mutation (e.g. null-ing out the package
     * for MU-plugins).
     */
    /** @return array<string, mixed>|object */
    abstract protected function prepareUpdateEntry(Update $update): array|object;

    /**
     * Returns the WP object to write into the transient no_update array,
     * indicating the entity is present and up to date. The current installed
     * version is provided as context for the implementation.
     *
     * @return array<string, mixed>|object
     */
    abstract protected function buildNoUpdateEntry(?string $currentVersion): array|object;

    /** Returns the full transient name ('site_transient_update_plugins' etc.). */
    abstract protected function getUpdateTransientName(): string;

    /** Returns the key under which this entity is indexed in WP's update arrays. */
    abstract protected function getUpdateListKey(): string;

    // =========================================================================
    // Abstract: WP.org exclusion
    // =========================================================================

    abstract protected function getWpOrgApiPath(): string;
    abstract protected function getWpOrgBodyKey(): string;

    /**
     * Removes this entity from the decoded WP.org update-check payload and
     * returns the modified payload for re-encoding.
     *
     * @param array<mixed> $payload Decoded JSON payload.
     * @return array<mixed>
     */
    abstract protected function removeFromWpOrgPayload(array $payload): array;

    // =========================================================================
    // Abstract: upgrader tracking
    // =========================================================================

    /** Returns the upgrader hook_extra key that holds this entity's identifier ('plugin'|'theme'). */
    abstract protected function getUpgraderHookExtraKey(): string;

    /** Returns the identifier to compare against the upgrader's entity key for isBeingUpgraded(). */
    abstract protected function getUpgraderEntityId(): string;

    /**
     * Falls back to reading the upgrader skin when hook-based tracking did not
     * fire. Returns null when the skin is not a recognised type.
     */
    abstract protected function extractEntityFromSkin(\WP_Upgrader $upgrader): ?string;

    /**
     * Returns the filesystem directory that contains this entity's directory.
     * Used to populate hook_extra['temp_backup']['src'] for WP 6.3+ rollback.
     * For plugins: WP_PLUGIN_DIR. For themes: get_theme_root($slug).
     */
    abstract protected function getEntitySourceDirectory(): string;

    // =========================================================================
    // Abstract: lifecycle hooks
    // =========================================================================

    /** Registers type-specific hooks (e.g. deactivation hook for plugins). */
    abstract protected function installTypeHooks(): void;

    /** Removes hooks registered by installTypeHooks(). */
    abstract protected function removeTypeHooks(): void;

    abstract protected function createAdminUI(): AbstractAdminUI;

    // =========================================================================
    // Optional overrides
    // =========================================================================

    /** Return false to suppress update injection (e.g. for unknown MU-plugins). */
    protected function shouldInjectUpdate(): bool
    {
        return true;
    }

    /**
     * Return false when the WordPress core update system will already exclude
     * this entity from the WP.org payload natively (e.g. via the Update URI
     * plugin header pointing to a non-wordpress.org host). When true, the
     * library registers an http_request_args filter to strip the entity from
     * the outgoing WP.org update-check request.
     */
    protected function shouldExcludeFromWpOrgChecks(): bool
    {
        return true;
    }

    /**
     * Called on a clone of the pending update before it is returned from
     * getUpdate(). Concrete classes can set type-specific fields (e.g. filename
     * for plugins).
     */
    protected function decorateUpdate(Update $update): Update
    {
        return $update;
    }

    // =========================================================================
    // Initialization
    // =========================================================================

    /**
     * Wires up all shared state and hooks. Concrete constructors must call
     * parent::__construct() after setting their own type-specific properties.
     */
    protected function __construct(
        private readonly GitHubClient $api,
        public readonly string $slug,
        int $checkPeriod,
        string $optionName,
    ) {
        $this->cbCollectApiErrors               = $this->collectApiErrors(...);
        $this->cbClearCachedVersion             = $this->clearCachedVersion(...);
        $this->cbSetUpgradedThing               = $this->setUpgradedThing(...);
        $this->cbSetUpgradedFromOptions         = $this->setUpgradedFromOptions(...);
        $this->cbClearUpgradedThing             = $this->clearUpgradedThing(...);
        $this->cbInjectInfo                     = $this->injectInfo(...);
        $this->cbInjectUpdate                   = $this->injectUpdate(...);
        $this->cbFixDirectoryName               = $this->fixDirectoryName(...);
        $this->cbInjectRollbackData             = $this->injectRollbackData(...);
        $this->cbAllowMetadataHost              = $this->allowMetadataHost(...);
        $this->cbExcludeEntityFromWordPressAPI  = $this->excludeEntityFromWordPressAPI(...);
        $this->cbInjectAuthorizationHeader      = $this->injectAuthorizationHeader(...);

        if (isset(self::$registeredSlugs[$this->slug])) {
            $this->triggerError(
                sprintf('Slug "%s" is already in use. Slugs must be unique.', $this->slug),
                E_USER_ERROR,
            );
        }
        self::$registeredSlugs[$this->slug] = $this->slug;

        $this->updateState = new UpdateState($optionName ?: "hu_state-" . $this->slug);
        $this->scheduler   = new Scheduler($this, max(0, $checkPeriod), $this->getSchedulerHooks());

        $transientKey = str_replace("site_transient_", "", $this->getUpdateTransientName());
        add_filter("upgrader_post_install",                  $this->cbClearCachedVersion);
        add_action("delete_site_transient_" . $transientKey, $this->cbClearCachedVersion);
        add_filter("upgrader_pre_install",                   $this->cbSetUpgradedThing,     10, 2);
        add_filter("upgrader_package_options",               $this->cbSetUpgradedFromOptions, 10, 1);
        add_filter("upgrader_post_install",                  $this->cbClearUpgradedThing,   10, 1);
        add_action("upgrader_process_complete",              $this->cbClearUpgradedThing,   10, 1);

        $this->installHooks();
        $this->installTypeHooks();
        $this->extraUi = $this->createAdminUI();

        $this->api->setStrategyFilterName($this->getUniqueName("vcs_update_detection_strategies"));
        $this->api->setSlug($this->slug);
    }

    private function installHooks(): void
    {
        add_filter($this->getInfoFilterName(),      $this->cbInjectInfo,                    20, 3);
        add_filter($this->getUpdateTransientName(), $this->cbInjectUpdate);
        add_filter("upgrader_source_selection",     $this->cbFixDirectoryName,              10, 3);
        add_filter("upgrader_package_options",      $this->cbInjectRollbackData,            11, 1);
        add_filter("http_request_host_is_external", $this->cbAllowMetadataHost,         10, 2);
        if ($this->shouldExcludeFromWpOrgChecks()) {
            add_filter("http_request_args", $this->cbExcludeEntityFromWordPressAPI, 10, 2);
        }
        add_filter("http_request_args", $this->cbInjectAuthorizationHeader, 10, 2);

        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command("hu {$this->slug}", new \WpHubUpdater\Cli\UpdaterCommand($this));
        }
    }

    /** Removes all registered WordPress hooks and releases this instance's slug. */
    public function removeHooks(): void
    {
        remove_filter($this->getInfoFilterName(),      $this->cbInjectInfo,                    20);
        remove_filter($this->getUpdateTransientName(), $this->cbInjectUpdate);
        remove_filter("upgrader_source_selection",     $this->cbFixDirectoryName,              10);
        remove_filter("upgrader_package_options",      $this->cbInjectRollbackData,            11);
        remove_filter("http_request_host_is_external", $this->cbAllowMetadataHost,             10);
        remove_filter("http_request_args",             $this->cbExcludeEntityFromWordPressAPI);
        remove_filter("http_request_args",             $this->cbInjectAuthorizationHeader);
        remove_filter("upgrader_post_install",         $this->cbClearCachedVersion);
        $transientKey = str_replace("site_transient_", "", $this->getUpdateTransientName());
        remove_action("delete_site_transient_" . $transientKey, $this->cbClearCachedVersion);
        remove_filter("upgrader_pre_install",          $this->cbSetUpgradedThing,     10);
        remove_filter("upgrader_package_options",      $this->cbSetUpgradedFromOptions, 10);
        remove_filter("upgrader_post_install",         $this->cbClearUpgradedThing,   10);
        remove_action("upgrader_process_complete",     $this->cbClearUpgradedThing,   10);

        unset(self::$registeredSlugs[$this->slug]);

        $this->removeTypeHooks();
        $this->extraUi->removeHooks();
        $this->scheduler->removeHooks();
    }

    // =========================================================================
    // Public API
    // =========================================================================

    /** Sets the GitHub personal access token used to authenticate API requests and ZIP downloads. */
    public function setAccessToken(string $token): static
    {
        $this->accessToken = $token;
        $this->api->setAccessToken($token);
        return $this;
    }

    /** Sets the text domain used to translate admin notices and manual-check result messages. */
    public function setTextDomain(string $domain): static
    {
        $this->textDomain = $domain;
        return $this;
    }

    /** @internal */
    public function getTextDomain(): string
    {
        return $this->textDomain;
    }

    /** Sets the branch used as the update source when no release or version tag is found. Defaults to main. */
    public function setBranch(string $branch): static
    {
        $this->branch = $branch;
        return $this;
    }

    /** Sets a custom callback to select which GitHub release to use as the update source. */
    public function setReleaseFilter(
        callable $callback,
        int $releaseTypes = self::RELEASE_FILTER_SKIP_PRERELEASE,
        int $maxReleases = 20,
    ): static {
        $this->api->setReleaseFilter($callback, $releaseTypes, $maxReleases);
        return $this;
    }

    /** Restricts update detection to releases whose version number matches the given regex. */
    public function setReleaseVersionFilter(
        string $regex,
        int $releaseTypes = self::RELEASE_FILTER_SKIP_PRERELEASE,
        int $maxReleasesToExamine = 20,
    ): static {
        $this->api->setReleaseVersionFilter($regex, $releaseTypes, $maxReleasesToExamine);
        return $this;
    }

    /** Restricts the download asset selection to the first release asset whose name contains the given string. */
    public function setAssetFilter(string $pattern): static
    {
        $this->api->setAssetFilter($pattern);
        return $this;
    }

    /** Extends the check interval when an update is already pending, reducing redundant API calls. */
    public function throttleRedundantChecks(bool $enable = true, int $hours = 72): static
    {
        $this->scheduler->setThrottle($enable, $hours);
        return $this;
    }

    /** Suppresses a newly published update for a given period after its release date. */
    public function setCooldown(int $hours = 24): static
    {
        $this->cooldownHours = max(0, $hours);
        return $this;
    }

    /** Forces debug mode on or off, overriding the automatic detection based on WP_DEBUG and environment type. When active, errors and warnings are sent to error_log(). */
    public function enableDebugMode(bool $enable = true): static
    {
        $this->debugMode = $enable;
        return $this;
    }

    /** Configures how many times transient failures (network errors and server errors) are retried with exponential backoff. */
    public function setMaxRetries(int $maxRetries, int $initialDelayMs = 500): static
    {
        $this->api->setMaxRetries($maxRetries, $initialDelayMs);
        return $this;
    }

    /** Clears all persisted check state, including the last-check timestamp and any stored update record. */
    public function resetState(): void
    {
        $this->updateState->reset();
    }

    /** Returns the Unix timestamp of the last completed update check, or 0 if no check has run yet. */
    public function getLastCheck(): int
    {
        return $this->updateState->getLastCheck();
    }

    /**
     * Opts this entity in (or out) of WordPress automatic background updates
     * by writing to the same site option the admin UI toggle uses.
     * The Plugins/Themes screen will immediately reflect the change.
     */
    public function enableAutoUpdates(bool $enable = true): static
    {
        $option   = "auto_update_" . $this->getEntityType() . "s";
        $entityId = $this->getUpdateListKey();
        $current  = (array) get_site_option($option, []);

        if ($enable) {
            if (!in_array($entityId, $current, true)) {
                $current[] = $entityId;
                update_site_option($option, $current);
            }
        } else {
            $filtered = array_values(array_filter($current, fn($id) => $id !== $entityId));
            if (count($filtered) !== count($current)) {
                update_site_option($option, $filtered);
            }
        }

        return $this;
    }

    /**
     * Forces an immediate update check against the GitHub API and records the result.
     * Returns null only when the installed version cannot be read. When a concurrent
     * check is already in progress, returns the last-known stored update without
     * making an additional API call.
     */
    public function checkForUpdates(): ?Update
    {
        $installedVersion = $this->getInstalledVersion();
        if ($installedVersion === null) {
            $this->triggerError(
                sprintf("Skipping update check for %s — installed version unknown.", $this->slug),
                E_USER_WARNING,
            );
            return null;
        }

        if (!$this->acquireCheckLock()) {
            return $this->getUpdate();
        }

        try {
            $this->lastRequestApiErrors = [];
            add_action("hu_api_error", $this->cbCollectApiErrors, 10, 4);

            $this->updateState
                ->setLastCheckToNow()
                ->setCheckedVersion($installedVersion)
                ->save();

            $this->updateState->setUpdate($this->requestUpdate());
            $this->updateState->save();
        } finally {
            remove_action("hu_api_error", $this->cbCollectApiErrors, 10);
            $this->releaseCheckLock();
        }

        return $this->getUpdate();
    }

    /**
     * Returns the stored update after applying the cooldown window and platform
     * requirements, or null when no qualifying update exists. Does not contact
     * the GitHub API.
     */
    public function getUpdate(): ?Update
    {
        $update = $this->updateState->getUpdate();

        if (isset($update)) {
            if ($update->version === null) {
                return null;
            }
            $installedVersion = $this->getInstalledVersion();
            if (
                $installedVersion !== null &&
                version_compare($update->version, $installedVersion, ">")
            ) {
                if ($this->isWithinCooldown($update)) {
                    return null;
                }
                if (
                    $update->requires_php !== null &&
                    version_compare(PHP_VERSION, $update->requires_php, '<')
                ) {
                    return null;
                }
                if (
                    $update->requires !== null &&
                    isset($GLOBALS['wp_version']) &&
                    version_compare((string) $GLOBALS['wp_version'], $update->requires, '<')
                ) {
                    return null;
                }
                return $this->decorateUpdate(clone $update);
            }
        }
        return null;
    }

    /**
     * Returns the scoped filter or action tag name for this updater instance.
     * Use this to register callbacks for filters specific to this plugin or theme.
     *
     * @return non-empty-string
     */
    public function getUniqueName(string $baseTag): string
    {
        return "hu_" . $baseTag . "-" . $this->slug;
    }

    /** Logs a PHP error when debug mode is active; silently no-ops otherwise. */
    protected function triggerError(string $message, int $errorType): void
    {
        $this->debugMode ??= (bool) WP_DEBUG ||
            in_array(wp_get_environment_type(), ["development", "staging"], true);
        if ($this->debugMode) {
            trigger_error(esc_html($message), $errorType);
        }
    }

    /**
     * Returns the API errors recorded during the most recent checkForUpdates() call.
     * Each entry contains the WP_Error, the raw HTTP response, and the request URL.
     *
     * @return array<int, array{error: \WP_Error, httpResponse: mixed, url: string|null}>
     */
    public function getLastRequestApiErrors(): array
    {
        return $this->lastRequestApiErrors;
    }

    /**
     * Returns a flat snapshot of the current update state, suitable for display.
     *
     * @return array<string, string>
     */
    public function getStatus(): array
    {
        $rawUpdate       = $this->updateState->getUpdate();
        $effectiveUpdate = $this->getUpdate();
        $lastCheck       = $this->updateState->getLastCheck();
        $installed       = $this->getInstalledVersion();

        $updateIsNewer = $rawUpdate instanceof \WpHubUpdater\Update
            && $rawUpdate->version !== null
            && version_compare($rawUpdate->version, $installed ?? '', '>');

        $cooldownActive       = $updateIsNewer && $rawUpdate instanceof \WpHubUpdater\Update && $this->isWithinCooldown($rawUpdate);
        $requirementBlocked   = $updateIsNewer && !$cooldownActive && !$effectiveUpdate instanceof \WpHubUpdater\Update;

        return [
            'slug'                 => $this->slug,
            'type'                 => $this->getEntityType(),
            'installed_version'    => $installed ?? 'unknown',
            'last_check'           => $lastCheck > 0 ? gmdate('Y-m-d H:i:s', $lastCheck) . ' UTC' : 'never',
            'available_version'    => ($rawUpdate instanceof \WpHubUpdater\Update && $rawUpdate->version !== null)
                ? $rawUpdate->version
                : 'none',
            'update_available'     => $effectiveUpdate instanceof \WpHubUpdater\Update ? 'yes' : 'no',
            'cooldown_active'      => $cooldownActive ? 'yes' : 'no',
            'requirement_blocked'  => $requirementBlocked ? 'yes' : 'no',
        ];
    }

    /** Cancels the scheduled cron event for this updater instance. */
    protected function removeUpdaterCron(): void
    {
        $this->scheduler->removeUpdaterCron();
    }

    // =========================================================================
    // Version caching
    // =========================================================================

    private function getInstalledVersion(): ?string
    {
        return $this->cachedInstalledVersion ??= $this->fetchInstalledVersion();
    }

    private function clearCachedVersion(mixed $filterArgument = null): mixed
    {
        $this->cachedInstalledVersion = null;
        return $filterArgument;
    }

    // =========================================================================
    // Update resolution
    // =========================================================================

    /** @return array{Reference, AbstractInfo}|null */
    private function resolveSource(): ?array
    {
        if (function_exists("set_time_limit")) {
            set_time_limit(60);
        }

        $updateSource = $this->api->chooseReference($this->branch);
        if (!$updateSource instanceof \WpHubUpdater\Reference) {
            do_action(
                "hu_api_error",
                new WP_Error(
                    "hu-no-update-source",
                    "Could not retrieve version information from the repository. " .
                        "This usually means that the update checker either can't connect " .
                        "to the repository or it's configured incorrectly.",
                ),
                null, null, $this->slug,
            );
            return null;
        }

        $info       = $this->createInfoObject();
        $info->slug = $this->slug;

        $this->populateInfoFromLocal($info);

        $info->version      = $updateSource->version;
        $info->last_updated = $updateSource->updated;
        $info->download_url = $updateSource->downloadUrl;

        if ($updateSource->downloadCount !== null) {
            $info->downloaded = $updateSource->downloadCount;
        }
        if (!in_array($updateSource->changelog, [null, ""], true)) {
            $info->sections["changelog"] = wp_kses_post($updateSource->changelog);
        }

        if (in_array($info->version, [null, ""], true)) {
            do_action(
                "hu_api_error",
                new WP_Error("hu-no-plugin-version", "Could not find the version number in the repository."),
                null, null, $this->slug,
            );
            return null;
        }

        $metadataFile = $this->getMetadataFilename();
        if ($metadataFile !== '') {
            $metadata = $this->api->getRemoteMetadata($metadataFile, $updateSource->name);
            if ($metadata !== null) {
                if (isset($metadata->requires_php) && is_string($metadata->requires_php)) {
                    $info->requires_php = $metadata->requires_php;
                }
                if (isset($metadata->requires_wp) && is_string($metadata->requires_wp)) {
                    $info->requires = $metadata->requires_wp;
                }
            }
        }

        return [$updateSource, $info];
    }

    private function requestInfo(): ?AbstractInfo
    {
        $resolved = $this->resolveSource();
        if ($resolved === null) {
            return null;
        }

        [$updateSource, $info] = $resolved;

        $this->populateInfoExtended($info);

        $metadataFile = $this->getMetadataFilename();
        if ($metadataFile !== '') {
            $metadata = $this->api->getRemoteMetadata($metadataFile, $updateSource->name);
            if ($metadata !== null) {
                $this->applyRemoteMetadata($info, $metadata);
            }
        }

        if (empty($info->sections["changelog"])) {
            $remoteChangelog = $this->api->getRemoteChangelog($updateSource->name, $this->getLocalDirectoryPath());
            $info->sections["changelog"] = $remoteChangelog !== null
                ? wp_kses_post($remoteChangelog)
                : __("There is no changelog available.", $this->textDomain);
        }

        return apply_filters($this->getUniqueName("request_info_result"), $info);
    }

    private function requestUpdate(): ?Update
    {
        $resolved = $this->resolveSource();
        if ($resolved === null) {
            return null;
        }

        [, $info] = $resolved;
        // Always receives a non-null Update. Return null or any non-Update value to suppress storing the update.
        $result = apply_filters($this->getUniqueName("request_update_result"), Update::fromObject($info));
        return $result instanceof Update ? $result : null;
    }

    private function applyRemoteMetadata(AbstractInfo $info, object $metadata): void
    {
        foreach (['requires_php'] as $field) {
            if (isset($metadata->$field) && is_string($metadata->$field) && $info->$field === null) {
                $info->$field = $metadata->$field;
            }
        }

        if (isset($metadata->requires_wp) && is_string($metadata->requires_wp) && $info->requires === null) {
            $info->requires = $metadata->requires_wp;
        }

        if (isset($metadata->tested_up_to) && is_string($metadata->tested_up_to) && $info->tested === null) {
            $info->tested = $metadata->tested_up_to;
        }

        if (isset($metadata->contributors) && is_object($metadata->contributors) && $info->contributors === null) {
            $contributors = [];
            foreach ((array) $metadata->contributors as $login => $contributor) {
                if (!is_object($contributor)) {
                    continue;
                }
                $contributors[$login] = [
                    'display_name' => isset($contributor->display_name) && is_string($contributor->display_name) ? $contributor->display_name : '',
                    'profile'      => isset($contributor->profile)      && is_string($contributor->profile)      ? $contributor->profile      : '',
                    'avatar'       => isset($contributor->avatar)       && is_string($contributor->avatar)       ? $contributor->avatar       : '',
                ];
            }
            if ($contributors !== []) {
                $info->contributors = $contributors;
            }
        }

        if (isset($metadata->sections) && is_object($metadata->sections)) {
            foreach ((array) $metadata->sections as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $info->sections[$key] = wp_kses_post($value);
                }
            }
        }

        if (isset($metadata->changelog) && is_array($metadata->changelog) && empty($info->sections["changelog"])) {
            $rendered = $this->renderStructuredChangelog(array_values($metadata->changelog));
            if ($rendered !== '') {
                $info->sections["changelog"] = $rendered;
            }
        }

        $this->applyTypeSpecificMetadata($info, $metadata);
    }

    /** @param list<mixed> $entries */
    private function renderStructuredChangelog(array $entries): string
    {
        $html = '';
        foreach ($entries as $entry) {
            if (!is_object($entry)) {
                continue;
            }
            $version = isset($entry->version) && is_string($entry->version) ? esc_html($entry->version) : '';
            $date    = isset($entry->date)    && is_string($entry->date)    ? esc_html($entry->date)    : '';
            $body    = isset($entry->body)    && is_string($entry->body)    ? $entry->body               : '';

            $heading = $version !== '' && $date !== ''
                ? "{$version} – {$date}"
                : ($version !== '' ? $version : $date);

            if ($heading !== '') {
                $html .= "<h4>{$heading}</h4>";
            }
            $html .= wp_kses_post($body);
        }
        return $html;
    }

    private function isWithinCooldown(Update $update): bool
    {
        if ($this->cooldownHours <= 0 || $update->last_updated === null) {
            return false;
        }
        $publishedAt = strtotime($update->last_updated);
        return $publishedAt !== false && (time() - $publishedAt) < ($this->cooldownHours * 3600);
    }

    // =========================================================================
    // WP hook callbacks: info + update injection
    // =========================================================================

    private function injectInfo(mixed $result, ?string $action = null, mixed $args = null): mixed
    {
        if (!$this->matchesInfoRequest($action, $args)) {
            return $result;
        }

        $info = $this->requestInfo();

        if ($info instanceof AbstractInfo) {
            return $info->toWpFormat();
        }

        return new \WP_Error(
            'hu-info-unavailable',
            sprintf(
                'Could not retrieve information for %s from GitHub. Check your access token and repository URL.',
                esc_html($this->getEntityTitle()),
            ),
        );
    }

    private function injectUpdate(mixed $updates): mixed
    {
        $update = $this->shouldInjectUpdate() ? $this->getUpdate() : null;

        if ($update instanceof Update) {
            $update = apply_filters($this->getUniqueName("pre_inject_update"), $update);
            if (!($update instanceof Update)) {
                $updates = $this->removeUpdateFromList($updates);
                return $this->addNoUpdateItem($updates);
            }
            return $this->addUpdateToList($updates, $update);
        }

        $updates = $this->removeUpdateFromList($updates);
        return $this->addNoUpdateItem($updates);
    }

    private function addUpdateToList(mixed $updates, Update $update): stdClass
    {
        if (!($updates instanceof stdClass)) {
            $updates = new stdClass();
        }
        if (!isset($updates->response) || !is_array($updates->response)) {
            $updates->response = [];
        }
        $updates->response[$this->getUpdateListKey()] = $this->prepareUpdateEntry($update);
        return $updates;
    }

    private function removeUpdateFromList(mixed $updates): mixed
    {
        if (isset($updates->response)) {
            unset($updates->response[$this->getUpdateListKey()]);
        }
        return $updates;
    }

    private function addNoUpdateItem(mixed $updates): stdClass
    {
        if (!($updates instanceof stdClass)) {
            $updates = new stdClass();
        }
        if (!isset($updates->response) || !is_array($updates->response)) {
            $updates->response = [];
        }
        if (!isset($updates->no_update) || !is_array($updates->no_update)) {
            $updates->no_update = [];
        }
        $updates->no_update[$this->getUpdateListKey()] = $this->buildNoUpdateEntry($this->getInstalledVersion());
        return $updates;
    }

    private function collectApiErrors(
        WP_Error $error,
        mixed $httpResponse = null,
        ?string $url = null,
        ?string $slug = null,
    ): void {
        if (isset($slug) && $slug !== $this->slug) {
            return;
        }
        $this->lastRequestApiErrors[] = ["error" => $error, "httpResponse" => $httpResponse, "url" => $url];
    }

    // =========================================================================
    // WP hook callbacks: HTTP & upgrader
    // =========================================================================

    private function allowMetadataHost(bool $allow, string $host): bool
    {
        if (in_array(strtolower($host), ["api.github.com", "codeload.github.com", "objects.githubusercontent.com"], true)) {
            return true;
        }
        if (!$this->metadataHostResolved) {
            $this->metadataHostResolved = true;
            $this->cachedMetadataHost   = wp_parse_url($this->api->getRepositoryUrl(), PHP_URL_HOST);
        }
        if (is_string($this->cachedMetadataHost) && strtolower($host) === strtolower($this->cachedMetadataHost)) {
            return true;
        }
        return $allow;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function excludeEntityFromWordPressAPI(array $args, string $url): array
    {
        $parsedUrl = wp_parse_url($url);
        if (!isset($parsedUrl["host"]) || strtolower($parsedUrl["host"]) !== "api.wordpress.org") {
            return $args;
        }
        if (!isset($parsedUrl["path"]) || !str_starts_with($parsedUrl["path"], $this->getWpOrgApiPath())) {
            return $args;
        }
        // Second argument is the concrete updater instance (PluginUpdater|ThemeUpdater).
        // Do not type-hint it as AbstractUpdater — that class is @internal.
        if (!apply_filters($this->getUniqueName("remove_from_default_update_checks"), true, $this, $args, $url)) {
            return $args;
        }

        $bodyKey = $this->getWpOrgBodyKey();
        if (empty($args["body"][$bodyKey])) {
            return $args;
        }

        $payload = json_decode((string) $args["body"][$bodyKey], true);
        if ($payload === null) {
            return $args;
        }

        $encoded = wp_json_encode($this->removeFromWpOrgPayload($payload));
        if ($encoded !== false) {
            $args["body"][$bodyKey] = $encoded;
        }
        return $args;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function injectAuthorizationHeader(array $args, string $url): array
    {
        if ($this->accessToken === null) {
            return $args;
        }
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) {
            return $args;
        }
        if (!in_array($host, ["github.com", "api.github.com"], true)) {
            return $args;
        }
        if ($this->cachedRepositoryPath === null) {
            $this->cachedRepositoryPath = $this->api->getRepositoryPath();
        }
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return $args;
        }
        $repoPath = $this->cachedRepositoryPath;
        if (
            $path !== "/repos/{$repoPath}"
            && !str_starts_with($path, "/repos/{$repoPath}/")
            && !str_starts_with($path, "/{$repoPath}/")
        ) {
            return $args;
        }
        $args["headers"] ??= [];
        $args["headers"]["Authorization"] = "token " . $this->accessToken;

        if (str_contains($url, "/releases/assets/")) {
            $args["headers"]["Accept"] = "application/octet-stream";
        }

        return $args;
    }

    private function isBeingUpgraded(): bool
    {
        return $this->upgradingEntity === $this->getUpgraderEntityId();
    }

    /** @param array<string, mixed> $hookExtra */
    private function setUpgradedThing(mixed $input, array $hookExtra): mixed
    {
        $key = $this->getUpgraderHookExtraKey();
        $this->upgradingEntity = !empty($hookExtra[$key]) && is_string($hookExtra[$key])
            ? $hookExtra[$key]
            : null;
        return $input;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function setUpgradedFromOptions(array $options): array
    {
        $key = $this->getUpgraderHookExtraKey();
        $this->upgradingEntity = isset($options["hook_extra"][$key]) && is_string($options["hook_extra"][$key])
            ? $options["hook_extra"][$key]
            : null;

        return $options;
    }

    private function clearUpgradedThing(mixed $input = null): mixed
    {
        $this->upgradingEntity = null;
        return $input;
    }

    private function fixDirectoryName(
        string $source,
        string $remoteSource,
        \WP_Upgrader $upgrader,
    ): string|\WP_Error {
        global $wp_filesystem;
        /** @var \WP_Filesystem_Base $wp_filesystem */

        if (!$this->ensureFilesystem()) {
            return $source;
        }

        if ($this->upgradingEntity === null) {
            $this->upgradingEntity = $this->extractEntityFromSkin($upgrader);
        }

        if (!$this->isBeingUpgraded()) {
            return $source;
        }

        if ($this->isBadDirectoryStructure($remoteSource)) {
            $newDirectory = trailingslashit($remoteSource) . $this->slug . "/";

            if (!$wp_filesystem->is_dir($newDirectory)) {
                $wp_filesystem->mkdir($newDirectory);

                $sourceFiles = $wp_filesystem->dirlist($remoteSource);
                if (is_array($sourceFiles)) {
                    $allMoved = true;
                    foreach (array_keys($sourceFiles) as $filename) {
                        if ($filename === $this->slug) {
                            continue;
                        }
                        if (!$wp_filesystem->move(
                            trailingslashit($remoteSource) . $filename,
                            trailingslashit($newDirectory) . $filename,
                            true,
                        )) {
                            $allMoved = false;
                            break;
                        }
                    }

                    if ($allMoved) {
                        $source = $newDirectory;
                    } else {
                        $wp_filesystem->rmdir($newDirectory, true);
                        return new \WP_Error(
                            "hu-incorrect-directory-structure",
                            sprintf(
                                'The directory structure of the update was incorrect. All files should be inside a directory named <span class="code">%s</span>, not at the root of the ZIP archive. Plugin Update Checker tried to fix the directory structure, but failed.',
                                esc_html($this->slug),
                            ),
                        );
                    }
                }
            }
        }

        $correctedSource = trailingslashit($remoteSource) . $this->getDirectoryName() . "/";
        if ($source !== $correctedSource) {
            $upgrader->skin->feedback(sprintf(
                "Renaming %s to %s&#8230;",
                '<span class="code">' . basename($source) . "</span>",
                '<span class="code">' . $this->getDirectoryName() . "</span>",
            ));

            if ($wp_filesystem->move($source, $correctedSource, true)) {
                $upgrader->skin->feedback("Directory successfully renamed.");
                return $correctedSource;
            }
            return new \WP_Error("hu-rename-failed", "Unable to rename the update to match the existing directory.");
        }

        return $source;
    }

    private function ensureFilesystem(): bool
    {
        global $wp_filesystem;
        if ($wp_filesystem instanceof \WP_Filesystem_Base) {
            return true;
        }
        if (!function_exists("WP_Filesystem")) {
            require_once ABSPATH . "/wp-admin/includes/file.php";
        }
        $initialized = WP_Filesystem();
        if ($initialized !== true) {
            $this->triggerError(
                sprintf(
                    'Could not initialize WP_Filesystem for "%s" during upgrader_source_selection.',
                    $this->slug,
                ),
                E_USER_WARNING,
            );
            return false;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function injectRollbackData(array $options): array
    {
        if (!$this->isBeingUpgraded() || !empty($options["hook_extra"]["temp_backup"])) {
            return $options;
        }
        $options["hook_extra"]["temp_backup"] = [
            'slug' => $this->getDirectoryName(),
            'dir'  => $this->getEntityType() . 's',
            'src'  => $this->getEntitySourceDirectory(),
        ];
        return $options;
    }

    private function acquireCheckLock(): bool
    {
        $key = "hu_check_lock-" . $this->slug;
        if (get_site_transient($key) !== false) {
            return false;
        }
        set_site_transient($key, 1, 60);
        return true;
    }

    private function releaseCheckLock(): void
    {
        delete_site_transient("hu_check_lock-" . $this->slug);
    }

    private function isBadDirectoryStructure(string $remoteSource): bool
    {
        global $wp_filesystem;
        /** @var \WP_Filesystem_Base $wp_filesystem */

        $sourceFiles = $wp_filesystem->dirlist($remoteSource);
        if (is_array($sourceFiles) && $sourceFiles !== []) {
            $sourceFiles   = array_keys($sourceFiles);
            $firstFilePath = trailingslashit($remoteSource) . $sourceFiles[0];
            return count($sourceFiles) > 1 || !$wp_filesystem->is_dir($firstFilePath);
        }
        return false;
    }
}
