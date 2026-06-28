<?php

namespace WpHubUpdater;

use Parsedown;

/**
 * Communicates with the GitHub REST API to discover the latest version of
 * a plugin and provide a validated download URL.
 *
 * Tries multiple detection strategies in priority order — latest published
 * release, highest version tag, then branch HEAD — and returns the first
 * successful result.
 *
 * @internal
 */
final class GitHubClient
{
    /** Strategy key: use the latest published release. */
    const string STRATEGY_LATEST_RELEASE = "latest_release";
    /** Strategy key: use the highest version-like tag. */
    const string STRATEGY_LATEST_TAG = "latest_tag";
    /** Strategy key: use the current HEAD of a branch. */
    const string STRATEGY_BRANCH = "branch";

    /** Include both stable and pre-release entries when scanning releases. */
    const int RELEASE_FILTER_ALL = 3;
    /** Skip pre-release entries when scanning releases (default). */
    const int RELEASE_FILTER_SKIP_PRERELEASE = 1;

    private string $slug = "";

    private string $strategyFilterName = "";

    private ?string $accessToken = null;

    private ?string $assetNamePattern = null;

    /** @var array<string, array{etag: string, body: string}>|null */
    private ?array $etagCache = null;

    private bool $etagCacheDirty = false;

    /** Cached changelog entry: {ref: string, changelog: string} or null when not yet loaded. */
    private ?object $changelogCache = null;

    private bool $changelogCacheLoaded = false;

    /** Cached metadata entry: {ref: string, data: object} or null when not yet loaded. */
    private ?object $metadataCache = null;

    private bool $metadataCacheLoaded = false;

    /** Unix timestamp until which all API calls should be skipped due to rate limiting. */
    private ?int $rateLimitBlockedUntil = null;

    private mixed $releaseFilterCallback = null;
    private int $releaseFilterMaxReleases = 1;
    private int $releaseFilterByType = self::RELEASE_FILTER_SKIP_PRERELEASE;

    private int $maxRetries = 2;
    private int $retryDelayMs = 500;

    private string $userName;
    private string $repositoryName;

    /**
     * Parses the repository URL into owner and repository name components.
     *
     * @throws \InvalidArgumentException When the URL does not contain a valid owner/repo path.
     */
    public function __construct(private readonly string $repositoryUrl)
    {
        $path = wp_parse_url($repositoryUrl, PHP_URL_PATH);
        if (
            preg_match(
                '@^/?(?P<username>[^/]+?)/(?P<repository>[^/#?&]+?)/?$@',
                $path,
                $matches,
            )
        ) {
            $this->userName = $matches["username"];
            $this->repositoryName = $matches["repository"];
        } else {
            throw new \InvalidArgumentException(
                'Invalid GitHub repository URL: "' . $repositoryUrl . '"',
            );
        }
    }

    /** Returns the configured GitHub repository URL. */
    public function getRepositoryUrl(): string
    {
        return $this->repositoryUrl;
    }

    /**
     * Runs the ordered detection strategies and returns the first successful
     * reference object. The strategy list can be customised via a WP filter
     * when a filter name has been configured.
     */
    public function chooseReference(string $configBranch): ?Reference
    {
        $strategies = $this->getUpdateDetectionStrategies($configBranch);

        $filterName = $this->strategyFilterName;
        if ($filterName !== "") {
            $strategies = apply_filters($filterName, $strategies, $this->slug);
            if (!is_array($strategies)) {
                return null;
            }
        }

        foreach ($strategies as $strategy) {
            if (!is_callable($strategy)) {
                continue;
            }
            $reference = $strategy();
            if ($reference !== null) {
                return $reference;
            }
        }
        return null;
    }

    /**
     * Fetches published releases and returns the best match according to the
     * configured release filter. Draft and pre-release entries are skipped
     * unless explicitly allowed via the filter settings.
     */
    public function getLatestRelease(): ?Reference
    {
        if (
            $this->shouldSkipPreReleases() &&
            ($this->releaseFilterMaxReleases === 1 ||
                !$this->hasCustomReleaseFilter())
        ) {
            $release = $this->api("/repos/:user/:repo/releases/latest");
            if (
                is_wp_error($release) ||
                !is_object($release) ||
                !isset($release->tag_name)
            ) {
                return null;
            }
            $foundReleases = [$release];
        } else {
            $foundReleases = $this->api("/repos/:user/:repo/releases", [
                "per_page" => $this->releaseFilterMaxReleases,
            ]);
            if (is_wp_error($foundReleases) || !is_array($foundReleases)) {
                return null;
            }
        }

        foreach ($foundReleases as $release) {
            if (!empty($release->draft)) {
                continue;
            }
            if (
                $this->shouldSkipPreReleases() &&
                !empty($release->prerelease)
            ) {
                continue;
            }

            $versionNumber = ltrim((string) $release->tag_name, "v");

            if (!$this->matchesCustomReleaseFilter($versionNumber, $release)) {
                continue;
            }

            $asset = $this->selectAsset($release);
            $downloadCount =
                $asset !== null &&
                isset($asset->download_count) &&
                is_int($asset->download_count)
                    ? $asset->download_count
                    : null;

            $changelog = null;
            if (!empty($release->body) && is_string($release->body)) {
                $changelog = class_exists("Parsedown", false)
                    ? Parsedown::instance()->text($release->body)
                    : $release->body;
            }

            return new Reference(
                name: (string) $release->tag_name,
                downloadUrl: $this->resolveAssetDownloadUrl($release),
                version: $versionNumber,
                updated: is_string($release->published_at ?? null)
                    ? $release->published_at
                    : (is_string($release->created_at ?? null) ? $release->created_at : null),
                changelog: $changelog,
                downloadCount: $downloadCount,
            );
        }

        return null;
    }

    /**
     * Fetches the repository's tag list and returns the highest version-like
     * tag as a reference object.
     */
    public function getLatestTag(): ?Reference
    {
        $tags = $this->api("/repos/:user/:repo/tags");
        if (is_wp_error($tags) || !is_array($tags)) {
            return null;
        }

        $versionTags = $this->sortTagsByVersion($tags);
        if ($versionTags === []) {
            return null;
        }

        $tag = $versionTags[0];
        $zipUrl =
            isset($tag->zipball_url) && is_string($tag->zipball_url)
                ? $tag->zipball_url
                : null;
        if ($zipUrl === null || !$this->isGitHubUrl($zipUrl)) {
            return null;
        }

        return new Reference(
            name: (string) $tag->name,
            downloadUrl: $zipUrl,
            version: ltrim((string) $tag->name, "v"),
            updated: null,
            changelog: null,
            downloadCount: null,
        );
    }

    /**
     * Returns a reference object representing the current HEAD of the given
     * branch, or null if the branch cannot be fetched.
     */
    public function getBranch(string $branchName): ?Reference
    {
        $branch = $this->api("/repos/:user/:repo/branches/" . $branchName);
        if (is_wp_error($branch) || empty($branch)) {
            return null;
        }

        $name = is_string($branch->name) ? $branch->name : $branchName;
        $updated = $branch->commit->commit->author->date ?? null;

        return new Reference(
            name: $name,
            downloadUrl: $this->buildArchiveDownloadUrl($name),
            version: null,
            updated: is_string($updated) ? $updated : null,
            changelog: null,
            downloadCount: null,
        );
    }

    /**
     * Executes a GitHub API request and returns the decoded JSON body, or a
     * WP_Error on HTTP failure or a non-200/304 status code.
     *
     * Sends an If-None-Match header when a cached ETag exists for the URL.
     * A 304 response returns the previously cached body without counting
     * against GitHub's rate limit.
     *
     * @param array<string, mixed> $queryParams
     */
    private function api(
        string $url,
        array $queryParams = [],
        bool $includeVersionHeader = true,
    ): mixed {
        $baseUrl = $url;
        $url = $this->buildApiUrl($url, $queryParams);

        if ($this->isCurrentlyRateLimited()) {
            return new \WP_Error(
                "hu-github-rate-limited",
                sprintf(
                    'GitHub API rate limit in effect, skipping request. Base URL: "%s".',
                    $baseUrl,
                ),
            );
        }

        $this->loadEtagCache();

        $options = $this->getApiRequestHttpOptions($includeVersionHeader);
        $cacheEntry = $this->etagCache[$url] ?? null;
        if ($cacheEntry !== null) {
            if ($this->isValidCacheEntry($cacheEntry)) {
                $options["headers"]["If-None-Match"] = $cacheEntry["etag"];
            } else {
                unset($this->etagCache[$url]);
                $this->etagCacheDirty = true;
                $cacheEntry = null;
            }
        }

        $response = $this->doHttpRequest($url, $options);

        if (is_wp_error($response)) {
            do_action("hu_api_error", $response, null, $url, $this->slug);
            $this->saveEtagCache();
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 304) {
            if ($cacheEntry === null) {
                $error = new \WP_Error(
                    "hu-github-http-error",
                    sprintf(
                        'GitHub returned 304 but no cached body exists. Base URL: "%s".',
                        $baseUrl,
                    ),
                );
                do_action("hu_api_error", $error, $response, $url, $this->slug);
                $this->saveEtagCache();
                return $error;
            }
            $this->saveEtagCache();
            return json_decode($cacheEntry["body"]);
        }

        if ($code === 200) {
            $etag = wp_remote_retrieve_header($response, "etag");
            if (is_string($etag) && $etag !== "") {
                $this->etagCache[$url] = ["etag" => $etag, "body" => $body];
                $this->etagCacheDirty = true;
            }
            $this->saveEtagCache();
            return json_decode($body);
        }

        if ($this->isRateLimited($code, $response)) {
            $resetTime = $this->getRateLimitResetTime($response);
            $this->storeRateLimitBlock($resetTime);
            $error = new \WP_Error(
                "hu-github-rate-limited",
                sprintf(
                    'GitHub API rate limit exceeded. Base URL: "%s".',
                    $baseUrl,
                ),
                ["reset_time" => $resetTime],
            );
            do_action("hu_api_error", $error, $response, $url, $this->slug);
            $this->saveEtagCache();
            return $error;
        }

        if ($code === 410 && $includeVersionHeader) {
            $error = new \WP_Error(
                "hu-github-api-version-expired",
                sprintf(
                    'GitHub API version header (2026-03-10) is no longer supported. Retrying without it — please update the wp-hub-updater library. Base URL: "%s".',
                    $baseUrl,
                ),
            );
            do_action("hu_api_error", $error, $response, $url, $this->slug);
            $this->saveEtagCache();
            return $this->api(
                $baseUrl,
                $queryParams,
                includeVersionHeader: false,
            );
        }

        if ($code === 401) {
            $error = new \WP_Error(
                "hu-github-auth-error",
                sprintf(
                    'GitHub authentication failed. Check that your access token is valid and has the required scopes. Base URL: "%s".',
                    $baseUrl,
                ),
            );
        } else {
            $error = new \WP_Error(
                "hu-github-http-error",
                sprintf(
                    'GitHub API error. Base URL: "%s", HTTP status code: %d.',
                    $baseUrl,
                    $code,
                ),
            );
        }
        do_action("hu_api_error", $error, $response, $url, $this->slug);
        $this->saveEtagCache();
        return $error;
    }

    /**
     * Constructs a full GitHub API endpoint URL, substituting `:user` and
     * `:repo` placeholders and appending any query parameters.
     *
     * @param array<string, mixed> $queryParams
     */
    private function buildApiUrl(string $url, array $queryParams): string
    {
        $url = str_replace(
            ["/:user", "/:repo"],
            [
                "/" . urlencode($this->userName),
                "/" . urlencode($this->repositoryName),
            ],
            $url,
        );
        $url = "https://api.github.com" . $url;

        if ($queryParams !== []) {
            return add_query_arg($queryParams, $url);
        }

        return $url;
    }

    /**
     * Fetches a file from the repository at the given ref via the contents API
     * and returns its decoded text content, or null on failure.
     */
    public function getRemoteFile(string $path, string $ref): ?string
    {
        $response = $this->api("/repos/:user/:repo/contents/" . $path, [
            "ref" => $ref,
        ]);
        if (
            is_wp_error($response) ||
            !($response instanceof \stdClass) ||
            !is_string($response->content) ||
            $response->encoding !== "base64"
        ) {
            return null;
        }
        $decoded = base64_decode(str_replace(["\r", "\n", " "], '', $response->content), strict: true);
        return $decoded !== false ? $decoded : null;
    }

    /**
     * Builds the ZIP archive download URL for the given ref using the GitHub
     * API zipball endpoint.
     */
    public function buildArchiveDownloadUrl(string $ref = "HEAD"): string
    {
        return sprintf(
            "https://api.github.com/repos/%s/%s/zipball/%s",
            urlencode($this->userName),
            urlencode($this->repositoryName),
            urlencode($ref),
        );
    }

    /**
     * Fetches and optionally renders the changelog file from the repository
     * at the given ref. Uses Parsedown when available. The result is cached
     * in the database keyed by ref so repeated popup opens skip the API call.
     * Null results (no changelog file found) are never cached.
     */
    public function getRemoteChangelog(
        string $ref,
        string $localDirectory,
    ): ?string {
        $cached = $this->getCachedChangelog($ref);
        if ($cached !== null) {
            return $cached;
        }

        $filename = $this->findChangelogName($localDirectory);
        if (in_array($filename, [null, ""], true)) {
            return null;
        }

        $changelog = $this->getRemoteFile($filename, $ref);
        if ($changelog === null) {
            return null;
        }

        $rendered = class_exists("Parsedown", false)
            ? Parsedown::instance()->text($changelog)
            : $changelog;

        $this->setCachedChangelog($ref, $rendered);
        return $rendered;
    }

    /**
     * Fetches and caches the metadata JSON file (hu.json) from
     * the repository at the given ref. Returns the decoded object, or null when
     * the file is absent, unreadable, or not valid JSON. Null results are never
     * cached, so an absent file is retried on the next popup open.
     */
    public function getRemoteMetadata(string $filename, string $ref): ?object
    {
        if (!$this->metadataCacheLoaded) {
            $stored = get_site_option("hu_metadata_cache-" . $this->slug, null);
            $this->metadataCache = is_object($stored) ? $stored : null;
            $this->metadataCacheLoaded = true;
        }

        if (
            $this->metadataCache !== null &&
            isset($this->metadataCache->ref, $this->metadataCache->data) &&
            $this->metadataCache->ref === $ref &&
            is_object($this->metadataCache->data)
        ) {
            return $this->metadataCache->data;
        }

        $content = $this->getRemoteFile($filename, $ref);
        if ($content === null) {
            return null;
        }

        $decoded = json_decode($content);
        if (!is_object($decoded)) {
            return null;
        }

        $entry = (object) ["ref" => $ref, "data" => $decoded];
        $this->metadataCache = $entry;
        if ($this->slug !== "") {
            $this->persistOption("hu_metadata_cache-" . $this->slug, $entry);
        }

        return $decoded;
    }

    /**
     * Returns the cached changelog for the given ref, or null on a cache miss
     * or when the stored entry belongs to a different ref.
     */
    private function getCachedChangelog(string $ref): ?string
    {
        if (!$this->changelogCacheLoaded) {
            $stored = get_site_option(
                "hu_changelog_cache-" . $this->slug,
                null,
            );
            $this->changelogCache = is_object($stored) ? $stored : null;
            $this->changelogCacheLoaded = true;
        }
        if (
            $this->changelogCache !== null &&
            isset(
                $this->changelogCache->ref,
                $this->changelogCache->changelog,
            ) &&
            $this->changelogCache->ref === $ref
        ) {
            return $this->changelogCache->changelog;
        }
        return null;
    }

    /** Persists the rendered changelog for the given ref to the database. */
    private function setCachedChangelog(string $ref, string $changelog): void
    {
        $entry = (object) ["ref" => $ref, "changelog" => $changelog];
        $this->changelogCache = $entry;
        if ($this->slug !== "") {
            $this->persistOption("hu_changelog_cache-" . $this->slug, $entry);
        }
    }

    /**
     * Scans a local directory for a recognisable changelog filename and
     * returns the first match, or null if none is found.
     */
    private function findChangelogName(string $directory): ?string
    {
        if ($directory === "" || $directory === "." || !is_dir($directory)) {
            return null;
        }

        $possibleNames = [
            "CHANGES.md",
            "CHANGELOG.md",
            "changes.md",
            "changelog.md",
        ];
        $entries = scandir($directory);
        if ($entries === false) {
            return null;
        }
        $found = array_intersect($possibleNames, $entries);
        return $found === [] ? null : reset($found);
    }

    /**
     * Returns HTTP options appropriate to the current execution context.
     * Cron requests use a longer timeout than interactive admin requests.
     * Includes the Authorization header when an access token is configured.
     * The version header is omitted on the fallback retry after a 410 response.
     *
     * @return array{timeout: int, headers: array<string, string>}
     */
    private function getApiRequestHttpOptions(
        bool $includeVersionHeader = true,
    ): array {
        $options = [
            "timeout" => wp_doing_cron() ? 10 : 3,
            "headers" => $includeVersionHeader
                ? ["X-GitHub-Api-Version" => "2026-03-10"]
                : [],
        ];
        if ($this->accessToken !== null) {
            $options["headers"]["Authorization"] =
                "token " . $this->accessToken;
        }
        return $options;
    }

    /**
     * Sets the WP filter name used to allow plugin code to modify the ordered
     * list of detection strategies.
     */
    public function setStrategyFilterName(string $filterName): void
    {
        $this->strategyFilterName = $filterName;
    }

    /** Sets the plugin slug, used when reporting API errors via the hu_api_error action. */
    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    /** Sets the GitHub personal access token used to authenticate API requests. */
    public function setAccessToken(string $token): void
    {
        $this->accessToken = $token !== '' ? $token : null;
    }

    /**
     * Restricts asset selection to the first release asset whose name contains
     * the given substring. Falls back to the first asset when no match is found.
     * Pass an empty string to clear a previously configured filter.
     */
    public function setAssetFilter(string $pattern): static
    {
        $this->assetNamePattern = $pattern === "" ? null : $pattern;
        return $this;
    }

    /**
     * Returns the best matching release asset according to the configured name
     * pattern. Falls back to the first asset when no pattern is set or no asset
     * name contains the pattern. Returns null when the release has no assets.
     */
    private function selectAsset(object $release): ?object
    {
        $assets = $release->assets ?? [];
        if (!is_array($assets) || $assets === []) {
            return null;
        }
        if ($this->assetNamePattern !== null) {
            foreach ($assets as $asset) {
                if (
                    isset($asset->name) &&
                    str_contains($asset->name, $this->assetNamePattern)
                ) {
                    return $asset;
                }
            }
        }
        return $assets[0];
    }

    /**
     * Configures a custom callback and parameters for selecting which GitHub
     * release to use as the update source.
     *
     * @throws \InvalidArgumentException When the release count is out of the allowed range.
     */
    public function setReleaseFilter(
        callable $callback,
        int $releaseTypes = self::RELEASE_FILTER_SKIP_PRERELEASE,
        int $maxReleases = 20,
    ): static {
        if ($maxReleases > 100 || $maxReleases < 1) {
            throw new \InvalidArgumentException(
                sprintf(
                    "Max releases must be between 1 and 100, got %d.",
                    $maxReleases,
                ),
            );
        }

        $this->releaseFilterCallback = $callback;
        $this->releaseFilterByType = $releaseTypes;
        $this->releaseFilterMaxReleases = $maxReleases;
        return $this;
    }

    /**
     * Configures release selection by matching the version number string
     * against a regular expression.
     */
    public function setReleaseVersionFilter(
        string $regex,
        int $releaseTypes = self::RELEASE_FILTER_SKIP_PRERELEASE,
        int $maxReleasesToExamine = 20,
    ): static {
        return $this->setReleaseFilter(
            fn(string $versionNumber) => preg_match($regex, $versionNumber) ===
                1,
            $releaseTypes,
            $maxReleasesToExamine,
        );
    }

    /**
     * Returns whether the given release passes the configured custom filter
     * callback. Always returns true when no filter has been configured.
     */
    private function matchesCustomReleaseFilter(
        string $versionNumber,
        object $releaseObject,
    ): bool {
        if (!is_callable($this->releaseFilterCallback)) {
            return true;
        }
        return (bool) call_user_func(
            $this->releaseFilterCallback,
            $versionNumber,
            $releaseObject,
        );
    }

    /** Returns whether pre-release entries should be excluded from detection. */
    private function shouldSkipPreReleases(): bool
    {
        return $this->releaseFilterByType !== self::RELEASE_FILTER_ALL;
    }

    /** Returns whether a custom release filter callback is active. */
    private function hasCustomReleaseFilter(): bool
    {
        return $this->releaseFilterCallback !== null &&
            is_callable($this->releaseFilterCallback);
    }

    /**
     * Builds the ordered map of detection strategy callbacks for the given
     * branch. Release and tag strategies are prepended when the branch is the
     * default main branch.
     *
     * @return array<string, callable(): ?Reference>
     */
    private function getUpdateDetectionStrategies(string $configBranch): array
    {
        $strategies = [];

        if ($configBranch === "master" || $configBranch === "main") {
            $strategies[
                self::STRATEGY_LATEST_RELEASE
            ] = $this->getLatestRelease(...);
            $strategies[self::STRATEGY_LATEST_TAG] = $this->getLatestTag(...);
        }

        $strategies[self::STRATEGY_BRANCH] = fn() => $this->getBranch(
            $configBranch,
        );

        return $strategies;
    }

    /** Returns whether a string resembles a version number. */
    private function looksLikeVersion(string $name): bool
    {
        $name = ltrim($name, "v");
        if (!is_numeric(substr($name, 0, 1))) {
            return false;
        }
        return preg_match(
            '@^(\d{1,5}?)(\.\d{1,10}?){0,4}?($|[abrdp+_\-]|\s)@i',
            $name,
        ) === 1;
    }

    /**
     * Returns whether a value is a stdClass with a version-like name.
     * @phpstan-assert-if-true \stdClass $tag
     */
    private function isVersionTag(mixed $tag): bool
    {
        return $tag instanceof \stdClass &&
            isset($tag->name) &&
            $this->looksLikeVersion($tag->name);
    }

    /**
     * Returns whether a URL points to a known GitHub-hosted domain.
     * Ensures download URLs are constrained to trusted GitHub infrastructure.
     */
    private function isGitHubUrl(string $url): bool
    {
        $host = wp_parse_url($url, PHP_URL_HOST);
        return is_string($host) &&
            in_array(
                $host,
                [
                    "github.com",
                    "api.github.com",
                    "codeload.github.com",
                    "objects.githubusercontent.com",
                ],
                true,
            );
    }

    /**
     * Filters the tag list to version-like tags and returns them sorted in
     * descending version order.
     *
     * @param array<mixed> $tags
     * @return list<\stdClass>
     */
    private function sortTagsByVersion(array $tags): array
    {
        $versionTags = array_values(
            array_filter($tags, $this->isVersionTag(...)),
        );
        usort($versionTags, $this->compareTagNames(...));
        return $versionTags;
    }

    /**
     * Compares two tag objects by version number in descending order.
     * Used as a usort comparator.
     */
    private function compareTagNames(\stdClass $tag1, \stdClass $tag2): int
    {
        if (!isset($tag1->name)) {
            return 1;
        }
        if (!isset($tag2->name)) {
            return -1;
        }
        return -version_compare(
            ltrim($tag1->name, "v"),
            ltrim($tag2->name, "v"),
        );
    }

    /**
     * Returns the best download URL for the selected release asset.
     *
     * When an access token is configured, the GitHub API asset endpoint is
     * preferred because it correctly handles redirects for both public and
     * private repositories. Falls back to the browser download URL for public
     * repositories without a token, then to the repository zipball when no
     * release asset is attached.
     */
    private function resolveAssetDownloadUrl(object $release): string
    {
        $asset = $this->selectAsset($release);

        if ($asset !== null && $this->accessToken !== null) {
            $apiUrl = $asset->url ?? null;
            if (is_string($apiUrl) && $this->isGitHubUrl($apiUrl)) {
                return $apiUrl;
            }
        }

        $browserUrl = $asset->browser_download_url ?? null;
        if (is_string($browserUrl) && $this->isGitHubUrl($browserUrl)) {
            return $browserUrl;
        }

        $zipballUrl = $release->zipball_url ?? null;
        return is_string($zipballUrl) && $this->isGitHubUrl($zipballUrl)
            ? $zipballUrl
            : $this->buildArchiveDownloadUrl();
    }

    /**
     * Returns whether the response indicates a rate-limit condition.
     * Covers GitHub's 429 code and the 403 variant where the remaining
     * request quota has dropped to zero.
     */
    private function isRateLimited(
        int|string|false $code,
        mixed $response,
    ): bool {
        if ($code === 429) {
            return true;
        }
        if ($code === 403) {
            $remaining = wp_remote_retrieve_header(
                $response,
                "x-ratelimit-remaining",
            );
            return $remaining === "0";
        }
        return false;
    }

    /**
     * Reads the reset time from the response headers.
     * Prefers Retry-After (a delay in seconds) over x-ratelimit-reset
     * (an absolute Unix timestamp). Returns null when neither header is present.
     */
    private function getRateLimitResetTime(mixed $response): ?int
    {
        $retryAfter = wp_remote_retrieve_header($response, "retry-after");
        if (is_numeric($retryAfter)) {
            return time() + (int) $retryAfter;
        }
        $resetAt = wp_remote_retrieve_header($response, "x-ratelimit-reset");
        if (is_numeric($resetAt)) {
            return (int) $resetAt;
        }
        return null;
    }

    /**
     * Persists a rate-limit block to the database. When no reset time is
     * provided by the server, a conservative default backoff is applied to
     * prevent a flood of follow-on requests.
     */
    private function storeRateLimitBlock(?int $resetTime): void
    {
        $until = $resetTime ?? time() + 3600;
        $this->rateLimitBlockedUntil = $until;
        if ($this->slug !== "") {
            $this->persistOption("hu_ratelimit-" . $this->slug, [
                "until" => $until,
            ]);
        }
    }

    /**
     * Returns whether a stored rate-limit block is still active. Removes the
     * option once the block period has passed so the database stays clean.
     */
    private function isCurrentlyRateLimited(): bool
    {
        if ($this->slug === "") {
            return false;
        }
        if ($this->rateLimitBlockedUntil === null) {
            $stored = get_site_option("hu_ratelimit-" . $this->slug, null);
            $this->rateLimitBlockedUntil =
                is_array($stored) && isset($stored["until"])
                    ? (int) $stored["until"]
                    : 0;
        }
        if ($this->rateLimitBlockedUntil > time()) {
            return true;
        }
        if ($this->rateLimitBlockedUntil > 0) {
            delete_site_option("hu_ratelimit-" . $this->slug);
            $this->rateLimitBlockedUntil = 0;
        }
        return false;
    }

    /**
     * Loads the ETag cache from the database on first access.
     * No-ops when already loaded or when the slug is not yet set.
     */
    private function loadEtagCache(): void
    {
        if ($this->etagCache !== null || $this->slug === "") {
            return;
        }
        $stored = get_site_option("hu_api_cache-" . $this->slug, []);
        $this->etagCache = is_array($stored) ? $stored : [];
        $this->sanitizeEtagCache();
    }

    /**
     * Configures the maximum number of retries for transient HTTP failures.
     * Only 5xx responses and network-level WP_Errors are retried.
     */
    public function setMaxRetries(
        int $maxRetries,
        int $initialDelayMs = 500,
    ): static {
        $this->maxRetries = max(0, $maxRetries);
        $this->retryDelayMs = max(0, $initialDelayMs);
        return $this;
    }

    /**
     * Executes the HTTP request with automatic retries for transient failures.
     * Retry attempts during interactive page loads are capped to protect
     * response times; full retries are only performed during cron runs.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>|\WP_Error
     */
    private function doHttpRequest(string $url, array $options): array|\WP_Error
    {
        $maxAttempts = wp_doing_cron()
            ? $this->maxRetries
            : min($this->maxRetries, 1);
        $response = wp_remote_get($url, $options);
        $code = is_wp_error($response)
            ? false
            : wp_remote_retrieve_response_code($response);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (!$this->isTransientFailure($response, $code)) {
                break;
            }
            usleep(min($this->retryDelayMs * 2 ** ($attempt - 1), 5000) * 1000);
            $response = wp_remote_get($url, $options);
            $code = is_wp_error($response)
                ? false
                : wp_remote_retrieve_response_code($response);
        }

        return $response;
    }

    /** Returns true for failures worth retrying: network errors and 5xx responses. */
    private function isTransientFailure(
        mixed $response,
        int|string|false $code,
    ): bool {
        if (is_wp_error($response)) {
            return true;
        }
        return in_array($code, [500, 502, 503, 504], true);
    }

    /** Returns true when a cache entry has the expected shape and a decodable body. */
    private function isValidCacheEntry(mixed $entry): bool
    {
        if (!is_array($entry) || !isset($entry["etag"], $entry["body"])) {
            return false;
        }
        if (!is_string($entry["etag"]) || $entry["etag"] === "") {
            return false;
        }
        if (!is_string($entry["body"]) || $entry["body"] === "") {
            return false;
        }
        json_decode($entry["body"]);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /** Removes invalid entries from the in-memory ETag cache and marks it dirty. */
    private function sanitizeEtagCache(): void
    {
        $removed = false;
        foreach ($this->etagCache as $url => $entry) {
            if (!$this->isValidCacheEntry($entry)) {
                unset($this->etagCache[$url]);
                $removed = true;
            }
        }
        if ($removed) {
            $this->etagCacheDirty = true;
        }
    }

    /**
     * Persists the ETag cache to the database when it has changed.
     * Resets the dirty flag after writing.
     */
    private function saveEtagCache(): void
    {
        if (
            !$this->etagCacheDirty ||
            $this->etagCache === null ||
            $this->slug === ""
        ) {
            return;
        }
        if (count($this->etagCache) > 30) {
            $this->etagCache = array_slice(
                $this->etagCache,
                -30,
                preserve_keys: true,
            );
        }
        $this->persistOption("hu_api_cache-" . $this->slug, $this->etagCache);
        $this->etagCacheDirty = false;
    }

    /**
     * Persists a site option with autoload disabled on single-site installs.
     * Cache and rate-limit options are only read on specific requests.
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
